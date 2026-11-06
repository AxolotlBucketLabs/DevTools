<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

final class BuildResult
{
    /**
     * @param array<string, string> $shadedVirions Original antigen => shaded namespace.
     * @param list<array<string, mixed>> $dependencies
     */
    public function __construct(
        public readonly string $pluginName,
        public readonly string $outputPath,
        public readonly int $fileCount,
        public readonly array $shadedVirions,
        public readonly array $dependencies = [],
    ) {}
}
