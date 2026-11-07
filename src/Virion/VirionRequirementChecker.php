<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

final class VirionRequirementChecker
{
    public function __construct(
        private readonly VirionRegistry $registry,
        private readonly VirionProjectSelector $selector,
    ) {}

    /** @param list<VirionRequirement> $requirements */
    public function failure(array $requirements): ?string
    {
        foreach ($requirements as $requirement) {
            $entry = $this->registry->findByName($requirement->name);
            if ($entry === null) {
                return "Required virion {$requirement->name} is missing. Requested: "
                    . $this->selector->describeRequirements($requirements)
                    . '. Add a compatible package to the selected virions directory.';
            }

            $manifest = $entry->requireManifest();
            if (!$requirement->matches($manifest->version)) {
                return "Required virion {$requirement->name} was not loaded with a compatible version. "
                    . "Loaded {$manifest->version}; requested "
                    . $this->selector->describeRequirements([$requirement])
                    . '.';
            }
        }

        return null;
    }
}
