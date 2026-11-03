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
    public function resolve(array $requirements, string $virionsDirectory): array
    {
        if ($requirements === []) {
            return [];
        }

        /** @var array<string, list<VirionProject>> $available */
        $available = [];
        $failures = [];
        $platform = new \NhanAZ\DevTools\Virion\VirionPlatformRequirements();
        foreach ($this->discovery->discover($virionsDirectory) as $location) {
            try {
                $project = $this->projectFactory->open($location);
                $platform->validateManifest($project->manifest);
                $available[strtolower($project->manifest->name)][] = $project;
            } catch (Throwable $error) {
                $failures[] = $error->getMessage();
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
                $projects[] = $this->projectFactory->open($location);
            } catch (Throwable) {
                // Invalid projects are diagnosed by runtime discovery and declared-name resolution.
            }
        }

        return $projects;
    }
}
