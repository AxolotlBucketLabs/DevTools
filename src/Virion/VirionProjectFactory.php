<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

use function bin2hex;
use function copy;
use function file_exists;
use function is_dir;
use function is_file;

use NhanAZ\DevTools\Support\Filesystem;
use Phar;

use function random_bytes;
use function realpath;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function sys_get_temp_dir;

use Throwable;

use function unlink;

final class VirionProjectFactory
{
    public function __construct(private readonly VirionManifestReader $manifestReader) {}

    public function open(string $location, bool $snapshotPhar = false): VirionProject
    {
        if (Filesystem::isLink($location)) {
            throw new VirionException("Refusing symbolic-link virion path: {$location}");
        }
        $isPhar = is_file($location) && str_ends_with(strtolower($location), '.phar');
        $temporaryPath = null;
        $originalRoot = '';
        if ($isPhar) {
            $real = realpath($location);
            if ($real === false) {
                throw new VirionException("Cannot resolve virion PHAR path: {$location}");
            }
            $originalRoot = 'phar://' . str_replace('\\', '/', $real);
            try {
                if ($snapshotPhar) {
                    $temporaryPath = $this->createSnapshot($real);
                    $real = $temporaryPath;
                }
                new Phar($real);
            } catch (Throwable $error) {
                if ($temporaryPath !== null && file_exists($temporaryPath)) {
                    unlink($temporaryPath);
                }
                throw new VirionException("Cannot open virion PHAR {$location}: {$error->getMessage()}", 0, $error);
            }
            $root = 'phar://' . str_replace('\\', '/', $real);
        } elseif (is_dir($location)) {
            $real = realpath($location);
            if ($real === false) {
                throw new VirionException("Cannot resolve virion folder path: {$location}");
            }
            $root = $real;
        } else {
            throw new VirionException("Virion path is neither a folder nor a PHAR: {$location}");
        }

        try {
            $manifestPath = $root . '/virion.yml';
            if (!is_file($manifestPath)) {
                throw new VirionException("Cannot load virion at {$location}: virion.yml is missing.");
            }
            if (!$isPhar && Filesystem::isLink($manifestPath)) {
                throw new VirionException("Cannot load virion at {$location}: virion.yml may not be a symbolic link.");
            }
            $manifest = $this->manifestReader->read($manifestPath);
            $source = $root . '/src';
            if (!$isPhar && Filesystem::isLink($source)) {
                throw new VirionException("Cannot load {$manifest->name}: source directory may not be a symbolic link: {$source}.");
            }
            if (!is_dir($source)) {
                throw new VirionException("Cannot load {$manifest->name}: source directory is missing at {$source}.");
            }

            $antigenRoot = $source;
            foreach (explode('\\', $manifest->antigen) as $component) {
                $antigenRoot .= '/' . $component;
                if (!$isPhar && Filesystem::isLink($antigenRoot)) {
                    throw new VirionException("Cannot load {$manifest->name}: antigen source path may not contain a symbolic link: {$antigenRoot}.");
                }
            }
            $sourceRoot = is_dir($antigenRoot) ? $antigenRoot : $source;

            return new VirionProject(
                $isPhar ? $originalRoot : $root,
                $sourceRoot,
                $manifest,
                $isPhar,
                $root,
                $temporaryPath,
            );
        } catch (Throwable $error) {
            if ($temporaryPath !== null && file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw $error;
        }
    }

    public function readFolderManifestName(string $location): ?string
    {
        if (!is_dir($location) || Filesystem::isLink($location)) {
            return null;
        }
        $manifestPath = $location . DIRECTORY_SEPARATOR . 'virion.yml';
        if (!is_file($manifestPath) || Filesystem::isLink($manifestPath)) {
            return null;
        }

        try {
            return $this->manifestReader->read($manifestPath)->name;
        } catch (Throwable) {
            return null;
        }
    }

    private function createSnapshot(string $source): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devtools-virion-' . bin2hex(random_bytes(12)) . '.phar';
            if (file_exists($target)) {
                continue;
            }
            if (copy($source, $target)) {
                return $target;
            }
        }

        throw new VirionException("Cannot create a temporary snapshot of virion PHAR {$source}.");
    }
}
