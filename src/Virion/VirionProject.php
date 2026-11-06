<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use Phar;
use Throwable;

final class VirionProject
{
    public readonly string $packageRoot;

    public readonly ?string $temporaryPath;

    public function __construct(
        public readonly string $location,
        public readonly string $sourceRoot,
        public readonly VirionManifest $manifest,
        public readonly bool $phar,
        string $packageRoot = '',
        ?string $temporaryPath = null,
    ) {
        $this->packageRoot = $packageRoot !== '' ? $packageRoot : $location;
        $this->temporaryPath = $temporaryPath;
    }

    public function __destruct()
    {
        if ($this->temporaryPath === null) {
            return;
        }
        try {
            Phar::unlinkArchive($this->temporaryPath);
        } catch (Throwable) {
            // The snapshot may not have been opened successfully.
        }
        if (is_file($this->temporaryPath)) {
            unlink($this->temporaryPath);
        }
    }
}
