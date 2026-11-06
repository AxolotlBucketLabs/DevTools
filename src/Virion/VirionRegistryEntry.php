<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

final class VirionRegistryEntry
{
    /**
     * @param list<string> $classes
     * @param list<string> $functions
     * @param list<string> $constants
     */
    public function __construct(
        public readonly string $location,
        public readonly ?VirionManifest $manifest,
        public readonly VirionStatus $status,
        public readonly string $message,
        public readonly array $classes = [],
        public readonly bool $asyncSupported = false,
        public readonly array $functions = [],
        public readonly array $constants = [],
    ) {}

    public function requireManifest(): VirionManifest
    {
        if ($this->manifest === null) {
            throw new \LogicException('A loaded virion registry entry must have a manifest.');
        }

        return $this->manifest;
    }
}
