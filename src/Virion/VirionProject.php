<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

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
        if ($this->temporaryPath !== null && is_file($this->temporaryPath)) {
            unlink($this->temporaryPath);
        }
    }
}
