<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function implode;

use NhanAZ\DevTools\Virion\VirionDependencyResolver;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionException;
use NhanAZ\DevTools\Virion\VirionProject;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionProjectSelector;
use NhanAZ\DevTools\Virion\VirionRequirement;

use function strtolower;

use Throwable;

final class VirionResolver
{
    public function __construct(
        private readonly VirionDiscovery $discovery,
        private readonly VirionProjectFactory $projectFactory,
        private readonly VirionProjectSelector $selector,
    ) {}

    /**
     * @param list<VirionRequirement> $requirements
     *
     * @return list<VirionProject>
     */
    public function resolve(array $requirements, string $virionsDirectory, bool $snapshotPhars = true): array
    {
        if ($requirements === []) {
            return [];
        }

        /** @var array<string, list<VirionProject>> $available */
        $available = [];
        /** @var array<string, list<array{string, VirionProject}>> $candidatesByName */
        $candidatesByName = [];
        /** @var array<string, string> $invalidFolderFailures */
        $invalidFolderFailures = [];
        $failures = [];
        $platform = new \NhanAZ\DevTools\Virion\VirionPlatformRequirements();
        foreach ($this->discovery->discover($virionsDirectory) as $location) {
            try {
                $project = $this->projectFactory->open($location, $snapshotPhars);
                $candidatesByName[strtolower($project->manifest->name)][] = [$location, $project];
            } catch (Throwable $error) {
                $name = $this->projectFactory->readFolderManifestName($location);
                if ($name !== null) {
                    $invalidFolderFailures[strtolower($name)] = $error->getMessage();
                }
                $failures[] = $error->getMessage();
            }
        }

        foreach ($invalidFolderFailures as $nameKey => $errorMessage) {
            if (!isset($candidatesByName[$nameKey])) {
                continue;
            }
            $hasFolder = false;
            foreach ($candidatesByName[$nameKey] as [, $candidate]) {
                if (!$candidate->phar) {
                    $hasFolder = true;
                    break;
                }
            }
            if ($hasFolder) {
                continue;
            }
            foreach ($candidatesByName[$nameKey] as [$location, $candidate]) {
                $failures[] = "Skipped {$candidate->manifest->name} PHAR {$location}: a development folder with the same name is invalid. {$errorMessage}";
            }
            unset($candidatesByName[$nameKey]);
        }

        foreach ($candidatesByName as $nameKey => $candidates) {
            $preferred = $this->preferredCandidates($candidates);
            foreach ($preferred as [$location, $project]) {
                try {
                    $platform->validateManifest($project->manifest);
                    $available[$nameKey][] = $project;
                } catch (Throwable $error) {
                    $failures[] = "{$location}: {$error->getMessage()}";
                }
            }
        }

        foreach ($requirements as $requirement) {
            $matches = $available[strtolower($requirement->name)] ?? [];
            if ($matches === []) {
                $details = $failures === [] ? '' : "\nOther virion errors:\n- " . implode("\n- ", $failures);
                throw new BuildException(
                    "Plugin declares virion {$requirement->name} {$requirement->constraint->expression}, but no valid virion with that name exists in {$virionsDirectory}. "
                    . "Add it to the configured virions directory or correct the declaration. DevTools does not download external dependencies automatically; prepare Git sources in the workflow or use the explicit Composer preparation flow. See https://github.com/NhanAZ/DevTools/blob/main/docs/dependencies.md.{$details}",
                );
            }
        }
        try {
            return (new VirionDependencyResolver($this->selector))->resolve($available, $requirements);
        } catch (VirionException $error) {
            throw new BuildException($error->getMessage(), 0, $error);
        }
    }

    /** @return list<VirionProject> */
    public function discoverValid(string $virionsDirectory): array
    {
        $projects = [];
        foreach ($this->discovery->discover($virionsDirectory) as $location) {
            try {
                $projects[] = $this->projectFactory->open($location, true);
            } catch (Throwable) {
                // Invalid projects are diagnosed by runtime discovery and declared-name resolution.
            }
        }

        return $projects;
    }

    /** @param list<array{string, VirionProject}> $candidates
     * @return list<array{string, VirionProject}>
     */
    private function preferredCandidates(array $candidates): array
    {
        foreach ($candidates as [, $candidate]) {
            if (!$candidate->phar) {
                return array_values(array_filter(
                    $candidates,
                    static fn(array $entry): bool => !$entry[1]->phar,
                ));
            }
        }

        return $candidates;
    }
}
