<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

use function class_exists;

use Closure;

use function defined;
use function enum_exists;
use function function_exists;
use function implode;
use function interface_exists;
use function ksort;
use function ltrim;

use pocketmine\plugin\ApiVersion;

use function realpath;
use function str_replace;
use function str_starts_with;
use function strtolower;

use Throwable;

use function trait_exists;

final class VirionManager
{
    /** @var array<string, true> */
    private array $processedLocations = [];

    /** @var array<string, 'loading'|'loaded'|'failed'> */
    private array $graphLoadStates = [];

    /** @var array<string, 'checking'|'ready'|'failed'> */
    private array $graphPreflightStates = [];

    /** @var array<string, VirionSourceSymbols> */
    private array $graphPreflightSymbols = [];

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
        /** @var array<string, string> $invalidFolderFailures */
        $invalidFolderFailures = [];
        foreach ($this->discovery->discover($directory) as $location) {
            try {
                $project = $this->projectFactory->open($location);
                $projectsByName[strtolower($project->manifest->name)][] = [$location, $project];
            } catch (Throwable $error) {
                $name = $this->projectFactory->readFolderManifestName($location);
                if ($name !== null) {
                    $invalidFolderFailures[strtolower($name)] = $error->getMessage();
                }
                $this->load($location);
            }
        }
        foreach ($invalidFolderFailures as $nameKey => $errorMessage) {
            if (!isset($projectsByName[$nameKey])) {
                continue;
            }
            $hasFolder = false;
            foreach ($projectsByName[$nameKey] as [, $candidate]) {
                if (!$candidate->phar) {
                    $hasFolder = true;
                    break;
                }
            }
            if ($hasFolder) {
                continue;
            }
            foreach ($projectsByName[$nameKey] as [$location, $candidate]) {
                $this->recordUnloaded(
                    $location,
                    $candidate,
                    VirionStatus::SKIPPED,
                    "Skipped {$candidate->manifest->name} {$candidate->manifest->version} at {$location}: a development folder with the same name could not be loaded. {$errorMessage}",
                );
            }
            unset($projectsByName[$nameKey], $requirementsByName[$nameKey]);
        }
        ksort($projectsByName, SORT_STRING);
        $hasTransitive = false;
        $available = [];
        /** @var array<string, array{string, VirionProject}> $selectedProjects */
        $selectedProjects = [];
        /** @var array<string, list<array{type: 'selected'|'skipped', location: string, project: VirionProject, message?: string}>> $selectionActions */
        $selectionActions = [];
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
                    $selectedProjects[$nameKey] = [$location, $selected];
                    $selectionActions[$nameKey][] = [
                        'type' => 'selected',
                        'location' => $location,
                        'project' => $project,
                    ];
                    continue;
                }
                $reason = $project->phar && $this->hasFolderCandidate($candidates)
                    ? 'a development folder for this virion name is preferred over a packaged PHAR'
                    : ($this->satisfies($project, $nameRequirements)
                        ? "a higher compatible version {$selected->manifest->version} was selected"
                        : 'it does not satisfy ' . $this->selector->describeRequirements($nameRequirements));
                $selectionActions[$nameKey][] = [
                    'type' => 'skipped',
                    'location' => $location,
                    'project' => $project,
                    'message' => "Skipped {$project->manifest->name} {$project->manifest->version} at {$location}: {$reason}.",
                ];
            }
            $this->recordPreferredPhars($candidates);
            unset($requirementsByName[$nameKey]);
        }

        $this->preflightSelectedDependencyCycles($selectedProjects);
        foreach ($projectsByName as $nameKey => $_candidates) {
            foreach ($selectionActions[$nameKey] ?? [] as $action) {
                if ($action['type'] === 'selected') {
                    $this->loadSelectedProject($nameKey, $selectedProjects);
                    continue;
                }
                $this->recordUnloaded($action['location'], $action['project'], VirionStatus::SKIPPED, $action['message'] ?? '');
            }
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

    /** @param array<string, array{string, VirionProject}> $selectedProjects */
    private function loadSelectedProject(string $nameKey, array $selectedProjects): bool
    {
        if (!$this->preflightSelectedProject($nameKey, $selectedProjects)) {
            $this->graphLoadStates[$nameKey] = 'failed';

            return false;
        }
        $state = $this->graphLoadStates[$nameKey] ?? null;
        if ($state === 'loaded') {
            return true;
        }
        if ($state === 'failed') {
            return false;
        }
        if ($state === 'loading') {
            return true;
        }
        if (!isset($selectedProjects[$nameKey])) {
            $this->graphLoadStates[$nameKey] = 'failed';

            return false;
        }

        $this->graphLoadStates[$nameKey] = 'loading';
        [$location, $project] = $selectedProjects[$nameKey];
        foreach ($project->manifest->requirements as $requirement) {
            $dependencyKey = strtolower($requirement->name);
            if (!isset($selectedProjects[$dependencyKey])) {
                $this->recordDependencyFailure(
                    $location,
                    $project,
                    "Required virion {$requirement->name} was not selected for {$project->manifest->name}.",
                );
                $this->graphLoadStates[$nameKey] = 'failed';

                return false;
            }
            if ($this->loadSelectedProject($dependencyKey, $selectedProjects)) {
                continue;
            }
            $dependency = $this->entryForLocation($selectedProjects[$dependencyKey][0]);
            $detail = $dependency === null ? 'The dependency failed before it could be diagnosed.' : $dependency->message;
            $this->recordDependencyFailure(
                $location,
                $project,
                "Cannot load {$project->manifest->name}: dependency {$requirement->name} failed. {$detail}",
            );
            $this->graphLoadStates[$nameKey] = 'failed';

            return false;
        }

        $entry = $this->load($location);
        $loaded = $entry?->status === VirionStatus::LOADED;
        $this->graphLoadStates[$nameKey] = $loaded ? 'loaded' : 'failed';

        return $loaded;
    }

    /** @param array<string, array{string, VirionProject}> $selectedProjects */
    private function preflightSelectedProject(string $nameKey, array $selectedProjects): bool
    {
        $state = $this->graphPreflightStates[$nameKey] ?? null;
        if ($state === 'ready') {
            return true;
        }
        if ($state === 'failed') {
            return false;
        }
        if ($state === 'checking') {
            return true;
        }
        if (!isset($selectedProjects[$nameKey])) {
            $this->graphPreflightStates[$nameKey] = 'failed';

            return false;
        }

        $this->graphPreflightStates[$nameKey] = 'checking';
        [$location, $project] = $selectedProjects[$nameKey];
        foreach ($project->manifest->requirements as $requirement) {
            $dependencyKey = strtolower($requirement->name);
            if (!isset($selectedProjects[$dependencyKey])) {
                $this->recordDependencyFailure(
                    $location,
                    $project,
                    "Required virion {$requirement->name} was not selected for {$project->manifest->name}.",
                );
                $this->graphPreflightStates[$nameKey] = 'failed';

                return false;
            }
            if ($this->preflightSelectedProject($dependencyKey, $selectedProjects)) {
                continue;
            }
            $dependency = $this->entryForLocation($selectedProjects[$dependencyKey][0]);
            $detail = $dependency === null ? 'The dependency failed before it could be diagnosed.' : $dependency->message;
            $this->recordDependencyFailure(
                $location,
                $project,
                "Cannot load {$project->manifest->name}: dependency {$requirement->name} failed. {$detail}",
            );
            $this->graphPreflightStates[$nameKey] = 'failed';

            return false;
        }

        try {
            $this->assertCompatible($project->manifest);
            $symbols = $this->classScanner->scanSymbols($project);
            if ($symbols->classes === []) {
                throw new VirionException("Cannot load {$project->manifest->name}: no PHP classes were found in {$project->sourceRoot}.");
            }
            $this->assertPreflightSymbolsAvailable($project, $symbols);
            $this->graphPreflightSymbols[$nameKey] = $symbols;
        } catch (Throwable $error) {
            $this->recordDependencyFailure($location, $project, $error->getMessage());
            $this->graphPreflightStates[$nameKey] = 'failed';

            return false;
        }

        $this->graphPreflightStates[$nameKey] = 'ready';

        return true;
    }

    /** @param array<string, array{string, VirionProject}> $selectedProjects */
    private function preflightSelectedDependencyCycles(array $selectedProjects): void
    {
        foreach (array_keys($selectedProjects) as $nameKey) {
            $this->preflightSelectedProject($nameKey, $selectedProjects);
        }

        do {
            $changed = false;
            foreach ($selectedProjects as $nameKey => [$location, $project]) {
                if (($this->graphPreflightStates[$nameKey] ?? null) !== 'ready') {
                    continue;
                }
                foreach ($project->manifest->requirements as $requirement) {
                    $dependencyKey = strtolower($requirement->name);
                    if (($this->graphPreflightStates[$dependencyKey] ?? null) !== 'failed') {
                        continue;
                    }
                    $dependency = $this->entryForLocation($selectedProjects[$dependencyKey][0] ?? '');
                    $detail = $dependency === null ? 'The dependency failed before it could be diagnosed.' : $dependency->message;
                    $this->recordDependencyFailure(
                        $location,
                        $project,
                        "Cannot load {$project->manifest->name}: dependency {$requirement->name} failed. {$detail}",
                    );
                    $this->graphPreflightStates[$nameKey] = 'failed';
                    $changed = true;
                    break;
                }
            }
        } while ($changed);

        $readyKeys = [];
        foreach (array_keys($selectedProjects) as $key) {
            if (($this->graphPreflightStates[$key] ?? null) === 'ready') {
                $readyKeys[] = $key;
            }
        }
        foreach ($this->stronglyConnectedComponents($selectedProjects) as $component) {
            $isCycle = count($component) > 1;
            if (!$isCycle) {
                $cycleKey = $component[0] ?? null;
                if ($cycleKey === null) {
                    continue;
                }
                foreach ($selectedProjects[$cycleKey][1]->manifest->requirements as $requirement) {
                    if (strtolower($requirement->name) === $cycleKey) {
                        $isCycle = true;
                        break;
                    }
                }
            }
            if (!$isCycle) {
                continue;
            }

            $conflict = null;
            $otherName = null;
            $componentCount = count($component);
            for ($left = 0; $left < $componentCount && $conflict === null; ++$left) {
                $leftKey = $component[$left];
                for ($right = $left + 1; $right < $componentCount; ++$right) {
                    $rightKey = $component[$right];
                    $conflict = $this->selectedProjectConflict($leftKey, $rightKey, $selectedProjects);
                    if ($conflict !== null) {
                        $otherName = $selectedProjects[$rightKey][1]->manifest->name;
                        break;
                    }
                }
            }
            if ($conflict === null) {
                foreach ($readyKeys as $externalKey) {
                    if (in_array($externalKey, $component, true)) {
                        continue;
                    }
                    foreach ($component as $cycleKey) {
                        $conflict = $this->selectedProjectConflict($cycleKey, $externalKey, $selectedProjects);
                        if ($conflict !== null) {
                            $otherName = $selectedProjects[$externalKey][1]->manifest->name;
                            break 2;
                        }
                    }
                }
            }
            if ($conflict === null) {
                foreach ($this->registry->loaded() as $loaded) {
                    $loadedManifest = $loaded->requireManifest();
                    foreach ($component as $cycleKey) {
                        $cycleProject = $selectedProjects[$cycleKey][1];
                        $conflict = $this->describeProjectConflict(
                            $cycleProject->manifest,
                            $this->graphPreflightSymbols[$cycleKey] ?? new VirionSourceSymbols([], [], [], []),
                            $loadedManifest,
                            new VirionSourceSymbols($loaded->classes, $loaded->functions, $loaded->constants, $loaded->functions),
                        );
                        if ($conflict !== null) {
                            $otherName = $loadedManifest->name;
                            break 2;
                        }
                    }
                }
            }
            if ($conflict === null) {
                continue;
            }

            foreach ($component as $cycleKey) {
                [$cycleLocation, $cycleProject] = $selectedProjects[$cycleKey];
                $this->recordDependencyFailure(
                    $cycleLocation,
                    $cycleProject,
                    "Cannot load {$cycleProject->manifest->name}: dependency cycle with {$otherName} has a {$conflict}.",
                );
                $this->graphPreflightStates[$cycleKey] = 'failed';
            }
        }
    }

    /**
     * @param array<string, array{string, VirionProject}> $selectedProjects
     * @return list<list<string>>
     */
    private function stronglyConnectedComponents(array $selectedProjects): array
    {
        $ready = [];
        foreach (array_keys($selectedProjects) as $key) {
            if (($this->graphPreflightStates[$key] ?? null) === 'ready') {
                $ready[$key] = true;
            }
        }

        $index = 0;
        /** @var array<string, int> $indices */
        $indices = [];
        /** @var array<string, int> $lowLinks */
        $lowLinks = [];
        /** @var list<string> $stack */
        $stack = [];
        /** @var array<string, true> $onStack */
        $onStack = [];
        /** @var list<list<string>> $components */
        $components = [];
        /** @var Closure(string): void $visit */
        $visit = function (string $node) use (&$visit, &$index, &$indices, &$lowLinks, &$stack, &$onStack, &$components, $ready, $selectedProjects): void {
            $indices[$node] = $index;
            $lowLinks[$node] = $index;
            ++$index;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach ($selectedProjects[$node][1]->manifest->requirements as $requirement) {
                $dependency = strtolower($requirement->name);
                if (!isset($ready[$dependency])) {
                    continue;
                }
                if (!isset($indices[$dependency])) {
                    $visit($dependency);
                    $lowLinks[$node] = min($lowLinks[$node], $lowLinks[$dependency]);
                } elseif (isset($onStack[$dependency])) {
                    $lowLinks[$node] = min($lowLinks[$node], $indices[$dependency]);
                }
            }

            if ($lowLinks[$node] !== $indices[$node]) {
                return;
            }

            $component = [];
            do {
                $member = array_pop($stack);
                if ($member === null) {
                    break;
                }
                unset($onStack[$member]);
                $component[] = $member;
            } while ($member !== $node);
            $components[] = $component;
        };

        foreach (array_keys($ready) as $key) {
            if (!isset($indices[$key])) {
                $visit($key);
            }
        }

        return $components;
    }

    /** @param array<string, array{string, VirionProject}> $selectedProjects */
    private function selectedProjectConflict(string $leftKey, string $rightKey, array $selectedProjects): ?string
    {
        return $this->describeProjectConflict(
            $selectedProjects[$leftKey][1]->manifest,
            $this->graphPreflightSymbols[$leftKey] ?? new VirionSourceSymbols([], [], [], []),
            $selectedProjects[$rightKey][1]->manifest,
            $this->graphPreflightSymbols[$rightKey] ?? new VirionSourceSymbols([], [], [], []),
        );
    }

    private function describeProjectConflict(
        VirionManifest $left,
        VirionSourceSymbols $leftSymbols,
        VirionManifest $right,
        VirionSourceSymbols $rightSymbols,
    ): ?string {
        if ($this->isNamespaceMember($left->antigen, $right->antigen)
            || $this->isNamespaceMember($right->antigen, $left->antigen)) {
            return "antigen {$left->antigen} conflicts with {$right->antigen}";
        }

        $rightClassMap = [];
        foreach ($rightSymbols->classes as $class) {
            $rightClassMap[strtolower($class)] = $class;
        }
        foreach ($leftSymbols->classes as $class) {
            if (isset($rightClassMap[strtolower($class)])) {
                return "class {$class} conflicts with {$rightClassMap[strtolower($class)]}";
            }
        }

        $rightFunctionMap = [];
        foreach ($rightSymbols->unconditionalFunctions as $function) {
            $rightFunctionMap[strtolower($function)] = $function;
        }
        foreach ($leftSymbols->unconditionalFunctions as $function) {
            if (isset($rightFunctionMap[strtolower($function)])) {
                return "function {$function} conflicts with {$rightFunctionMap[strtolower($function)]}";
            }
        }

        $rightConstantMap = array_fill_keys($rightSymbols->constants, true);
        foreach ($leftSymbols->constants as $constant) {
            if (isset($rightConstantMap[$constant])) {
                return "constant {$constant} conflicts with {$constant}";
            }
        }

        return null;
    }

    private function assertPreflightSymbolsAvailable(VirionProject $project, VirionSourceSymbols $symbols): void
    {
        foreach ($symbols->classes as $class) {
            $this->assertClassIsNotLoaded($class, $project->manifest->name);
            $existing = $this->registry->findByClass($class);
            if ($existing !== null) {
                throw new VirionException("Class {$class} is already provided by {$existing->requireManifest()->name}.");
            }
        }
        foreach ($symbols->unconditionalFunctions as $function) {
            if (function_exists($function)) {
                $origin = 'the server or another plugin';
                try {
                    $file = (new \ReflectionFunction($function))->getFileName();
                    if ($file !== false) {
                        $origin = $file;
                    }
                } catch (\ReflectionException) {
                }
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: function {$function} is already loaded from {$origin}; refusing to register a second implementation.",
                );
            }
            $existing = $this->registry->findByFunction($function);
            if ($existing !== null) {
                throw new VirionException("Function {$function} is already provided by {$existing->requireManifest()->name}.");
            }
        }
        foreach ($symbols->constants as $constant) {
            if (defined($constant)) {
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: constant {$constant} is already defined; refusing to register a second implementation.",
                );
            }
            $existing = $this->registry->findByConstant($constant);
            if ($existing !== null) {
                throw new VirionException("Constant {$constant} is already provided by {$existing->requireManifest()->name}.");
            }
        }
    }

    private function recordDependencyFailure(string $location, VirionProject $project, string $message): void
    {
        $this->processedLocations[$this->canonicalLocation($location)] = true;
        $this->registry->record(new VirionRegistryEntry(
            $location,
            $project->manifest,
            VirionStatus::FAILED,
            $message,
        ));
    }

    private function entryForLocation(string $location): ?VirionRegistryEntry
    {
        $canonical = $this->canonicalLocation($location);
        foreach ($this->registry->entries() as $entry) {
            if ($this->canonicalLocation($entry->location) === $canonical) {
                return $entry;
            }
        }

        return null;
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
            $symbols = $this->classScanner->scanSymbols($project);
            $classes = $symbols->classes;
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
                $this->assertClassIsNotLoaded($class, $manifest->name);
                $existingClass = $this->registry->findByClass($class);
                if ($existingClass !== null) {
                    throw new VirionException("Class {$class} is already provided by {$existingClass->requireManifest()->name}.");
                }
            }
            $this->assertPreflightSymbolsAvailable($project, $symbols);

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
            $entry = new VirionRegistryEntry(
                $location,
                $manifest,
                VirionStatus::LOADED,
                $message,
                $classes,
                $async,
                $symbols->unconditionalFunctions,
                $symbols->constants,
            );
        } catch (Throwable $error) {
            $manifest = isset($project) ? $project->manifest : null;
            $status = $this->looksLikeConflict($error->getMessage()) ? VirionStatus::CONFLICT : VirionStatus::FAILED;
            $entry = new VirionRegistryEntry($location, $manifest, $status, $error->getMessage());
        }

        $this->registry->record($entry);

        return $entry;
    }

    private function assertClassIsNotLoaded(string $class, string $virionName): void
    {
        if (!class_exists($class, false)
            && !interface_exists($class, false)
            && !trait_exists($class, false)
            && !enum_exists($class, false)) {
            return;
        }

        $origin = 'the server or another plugin';
        try {
            $file = (new \ReflectionClass($class))->getFileName();
            if ($file !== false) {
                $origin = $file;
            }
        } catch (\ReflectionException) {
        }

        throw new VirionException(
            "Cannot load {$virionName}: class {$class} is already loaded from {$origin}; refusing to register a second implementation.",
        );
    }

    private function assertCompatible(VirionManifest $manifest): void
    {
        (new VirionPlatformRequirements())->validateManifest($manifest);
        if ($manifest->api !== [] && !ApiVersion::isCompatible($this->serverApiVersion, $manifest->api)) {
            throw new VirionException(
                "Cannot load {$manifest->name}: it declares API " . implode(', ', $manifest->api) . ", but this server provides {$this->serverApiVersion}.",
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
