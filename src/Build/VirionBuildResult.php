<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Virion\VirionManifest;

final class VirionBuildResult
{
    public function __construct(
        public readonly VirionManifest $manifest,
        public readonly string $outputPath,
        public readonly int $fileCount,
    ) {}
}
