<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

use function implode;
use function ksort;
use function ltrim;

use pocketmine\plugin\ApiVersion;

use function realpath;
use function str_replace;
use function str_starts_with;
use function strtolower;

use Throwable;

use function version_compare;

final class VirionManager
{
    /** @var array<string, true> */
    private array $processedLocations = [];

    public function __construct(
        private readonly VirionDiscovery $discovery,
        private readonly VirionProjectFactory $projectFactory,
        private readonly VirionClassScanner $classScanner,
        private readonly ClassLoaderRegistrar $registrar,
        private readonly VirionRegistry $registry,
        private readonly VirionProjectSelector $selector,
        private readonly string $serverApiVersion,
    ) {}

    /** @param list<VirionRequirement> $requirements */
    public function discoverAndLoad(string $directory, array $requirements = []): VirionRegistry
    {
        /** @var array<string, list<VirionRequirement>> $requirementsByName */
        $requirementsByName = [];
        $hasComposerRequirements = false;
        foreach ($requirements as $requirement) {
            $requirementsByName[strtolower($requirement->name)][] = $requirement;
            $hasComposerRequirements = $hasComposerRequirements || $requirement->composerPackageSha256 !== null;
        }

        /** @var array<string, list<array{string, VirionProject}>> $projectsByName */
        $projectsByName = [];
        foreach ($this->discovery->discover($directory) as $location) {
            try {
                $project = $this->projectFactory->open($location);
                $projectsByName[strtolower($project->manifest->name)][] = [$location, $project];
            } catch (Throwable) {
                $this->load($location);
            }
        }
        ksort($projectsByName, SORT_STRING);
        $hasTransitive = false;
        $available = [];
        foreach ($projectsByName as $nameKey => $candidates) {
            foreach ($this->preferredCandidates($candidates) as [, $project]) {
                $hasTransitive = $hasTransitive || $project->manifest->requirements !== [];
                try {
                    $this->assertCompatible($project->manifest);
                    $available[$nameKey][] = $project;
                } catch (VirionException) {
                }
            }
        }
        if ($hasTransitive || $hasComposerRequirements) {
            $graphRequirements = $requirements;
            foreach ($available as $nameKey => $projects) {
                if (!isset($requirementsByName[$nameKey])) {
                    $graphRequirements[] = new VirionRequirement($projects[0]->manifest->name, '*', $directory);
                }
            }
            try {
                foreach ((new VirionDependencyResolver($this->selector))->resolve($available, $graphRequirements) as $selected) {
                    $requirementsByName[strtolower($selected->manifest->name)][] = new VirionRequirement($selected->manifest->name, $selected->manifest->version, 'resolved shared dependency graph');
                }
            } catch (VirionException $error) {
                if ($projectsByName === []) {
                    $this->registry->record(new VirionRegistryEntry($directory, null, VirionStatus::FAILED, $error->getMessage()));
                }
                foreach ($projectsByName as $candidates) {
                    foreach ($candidates as [$location, $project]) {
                        $this->recordUnloaded($location, $project, VirionStatus::CONFLICT, $error->getMessage());
                    }
                }
                return $this->registry;
            }
        }
        foreach ($projectsByName as $nameKey => $candidates) {
            $preferredCandidates = $this->preferredCandidates($candidates);
            $compatibleCandidates = [];
            foreach ($preferredCandidates as [$location, $candidateProject]) {
                try {
                    $this->assertCompatible($candidateProject->manifest);
                    $compatibleCandidates[] = [$location, $candidateProject];
                } catch (VirionException $error) {
                    $this->recordUnloaded(
                        $location,
                        $candidateProject,
                        VirionStatus::FAILED,
                        $error->getMessage(),
                    );
                }
            }
            if ($compatibleCandidates === []) {
                $this->recordPreferredPhars($candidates);
                unset($requirementsByName[$nameKey]);
                continue;
            }
            $projects = [];
            foreach ($compatibleCandidates as [, $candidateProject]) {
                $projects[] = $candidateProject;
            }
            $name = $projects[0]->manifest->name;
            $nameRequirements = $requirementsByName[$nameKey] ?? [];
            try {
                $selected = $this->selector->select($name, $projects, $nameRequirements);
            } catch (VirionException $error) {
                foreach ($compatibleCandidates as [$location, $project]) {
                    $this->recordUnloaded($location, $project, VirionStatus::CONFLICT, $error->getMessage());
                }
                unset($requirementsByName[$nameKey]);
                continue;
            }

            foreach ($compatibleCandidates as [$location, $project]) {
                if ($project === $selected) {
                    $this->load($location);
                    continue;
                }
                $reason = $project->phar && $this->hasFolderCandidate($candidates)
                    ? 'a development folder for this virion name is preferred over a packaged PHAR'
                    : ($this->satisfies($project, $nameRequirements)
                        ? "a higher compatible version {$selected->manifest->version} was selected"
                        : 'it does not satisfy ' . $this->selector->describeRequirements($nameRequirements));
                $this->recordUnloaded(
                    $location,
                    $project,
                    VirionStatus::SKIPPED,
                    "Skipped {$project->manifest->name} {$project->manifest->version} at {$location}: {$reason}.",
                );
            }
            $this->recordPreferredPhars($candidates);
            unset($requirementsByName[$nameKey]);
        }

        foreach ($requirementsByName as $missingRequirements) {
            $first = $missingRequirements[0];
            $this->registry->record(new VirionRegistryEntry(
                $directory,
                null,
                VirionStatus::FAILED,
                "Required virion {$first->name} is missing. Requested: "
                . $this->selector->describeRequirements($missingRequirements)
                . ". Add a compatible package to {$directory}.",
            ));
        }

        return $this->registry;
    }

    public function load(string $location): ?VirionRegistryEntry
    {
        $locationKey = $this->canonicalLocation($location);
        if (isset($this->processedLocations[$locationKey])) {
            foreach ($this->registry->entries() as $entry) {
                if ($this->canonicalLocation($entry->location) === $locationKey) {
                    return $entry;
                }
            }

            return null;
        }
        $this->processedLocations[$locationKey] = true;

        try {
            $project = $this->projectFactory->open($location);
            $manifest = $project->manifest;
            $this->assertCompatible($manifest);
            $classes = $this->classScanner->scan($project);
            if ($classes === []) {
                throw new VirionException("Cannot load {$manifest->name}: no PHP classes were found in {$project->sourceRoot}.");
            }
            $projectClasses = [];
            foreach ($classes as $class) {
                $classKey = strtolower($class);
                if (isset($projectClasses[$classKey])) {
                    throw new VirionException("Cannot load {$manifest->name}: class {$class} is declared more than once.");
                }
                $projectClasses[$classKey] = true;
                if (!str_starts_with($class, $manifest->antigen . '\\')) {
                    throw new VirionException("Cannot load {$manifest->name}: class {$class} is outside antigen {$manifest->antigen}.");
                }
                $existingClass = $this->registry->findByClass($class);
                if ($existingClass !== null) {
                    throw new VirionException("Class {$class} is already provided by {$existingClass->requireManifest()->name}.");
                }
            }

            $existingName = $this->registry->findByName($manifest->name);
            if ($existingName !== null) {
                throw new VirionException("Virion name {$manifest->name} is already loaded from {$existingName->location}.");
            }
            foreach ($this->registry->loaded() as $loaded) {
                $loadedManifest = $loaded->requireManifest();
                $loadedAntigen = $loadedManifest->antigen;
                if ($this->isNamespaceMember($manifest->antigen, $loadedAntigen) || $this->isNamespaceMember($loadedAntigen, $manifest->antigen)) {
                    throw new VirionException("Namespace {$manifest->antigen} conflicts with {$loadedAntigen} from {$loadedManifest->name}.");
                }
            }

            $this->registrar->register($manifest->antigen, $project->sourceRoot);
            $async = $this->registrar->supportsAsyncWorkers();
            $message = "Loaded {$manifest->name} {$manifest->version} ({$manifest->antigen}). " . $this->registrar->asyncSupportDescription();
            $entry = new VirionRegistryEntry($location, $manifest, VirionStatus::LOADED, $message, $classes, $async);
        } catch (Throwable $error) {
            $manifest = isset($project) ? $project->manifest : null;
            $status = $this->looksLikeConflict($error->getMessage()) ? VirionStatus::CONFLICT : VirionStatus::FAILED;
            $entry = new VirionRegistryEntry($location, $manifest, $status, $error->getMessage());
        }

        $this->registry->record($entry);

        return $entry;
    }

    private function assertCompatible(VirionManifest $manifest): void
    {
        if (isset($manifest->raw['composer-platform'])) {
            (new VirionPlatformRequirements())->validate($manifest->raw['composer-platform'], $manifest->name);
        }
        if ($manifest->api !== [] && !ApiVersion::isCompatible($this->serverApiVersion, $manifest->api)) {
            throw new VirionException(
                "Cannot load {$manifest->name}: it declares API " . implode(', ', $manifest->api) . ", but this server provides {$this->serverApiVersion}.",
            );
        }
        if ($manifest->php !== []) {
            foreach ($manifest->php as $minimum) {
                if (version_compare(PHP_VERSION, $minimum, '>=')) {
                    return;
                }
            }
            throw new VirionException(
                "Cannot load {$manifest->name}: it requires one of PHP " . implode(', ', $manifest->php)
                . ' or newer. This server uses ' . PHP_VERSION . '.',
            );
        }
    }

    private function canonicalLocation(string $location): string
    {
        $resolved = realpath($location);

        return str_replace('\\', '/', $resolved === false ? $location : $resolved);
    }

    private function isNamespaceMember(string $candidate, string $prefix): bool
    {
        $candidate = strtolower(ltrim($candidate, '\\'));
        $prefix = strtolower(ltrim($prefix, '\\'));

        return $candidate === $prefix || str_starts_with($candidate, $prefix . '\\');
    }

    private function looksLikeConflict(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'already') || str_contains($message, 'conflict');
    }

    /** @param list<VirionRequirement> $requirements */
    private function satisfies(VirionProject $project, array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if (!$requirement->matches($project->manifest->version)) {
                return false;
            }
        }

        return true;
    }

    private function recordUnloaded(
        string $location,
        VirionProject $project,
        VirionStatus $status,
        string $message,
    ): void {
        $locationKey = $this->canonicalLocation($location);
        if (isset($this->processedLocations[$locationKey])) {
            return;
        }
        $this->processedLocations[$locationKey] = true;
        $this->registry->record(new VirionRegistryEntry($location, $project->manifest, $status, $message));
    }

    /** @param list<array{string, VirionProject}> $candidates */
    private function hasFolderCandidate(array $candidates): bool
    {
        foreach ($candidates as [, $candidate]) {
            if (!$candidate->phar) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{string, VirionProject}> $candidates
     * @return list<array{string, VirionProject}>
     */
    private function preferredCandidates(array $candidates): array
    {
        if (!$this->hasFolderCandidate($candidates)) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => !$candidate[1]->phar,
        ));
    }

    /** @param list<array{string, VirionProject}> $candidates */
    private function recordPreferredPhars(array $candidates): void
    {
        if (!$this->hasFolderCandidate($candidates)) {
            return;
        }
        foreach ($candidates as [$location, $project]) {
            if (!$project->phar) {
                continue;
            }
            $this->recordUnloaded(
                $location,
                $project,
                VirionStatus::SKIPPED,
                "Skipped {$project->manifest->name} {$project->manifest->version} at {$location}: a development folder for this virion name is preferred over a packaged PHAR.",
            );
        }
    }
}
