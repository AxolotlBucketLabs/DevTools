<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Loader;

use function array_unique;
use function array_values;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use FilesystemIterator;

use function is_dir;
use function is_file;
use function pathinfo;

use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function str_starts_with;
use function strtolower;

use Throwable;

final class PluginSourceConflictDetector
{
    private readonly PhpSourceInspector $sourceInspector;

    public function __construct(
        private readonly PluginManifestReader $manifestReader,
        ?PhpSourceInspector $sourceInspector = null,
    ) {
        $this->sourceInspector = $sourceInspector ?? new PhpSourceInspector();
    }

    /** @return list<string> */
    public function detect(string $pluginsDirectory): array
    {
        if (!is_dir($pluginsDirectory)) {
            return [];
        }

        /** @var array<string, list<string>> $folders */
        $folders = [];
        /** @var array<string, list<string>> $phars */
        $phars = [];
        $iterator = new FilesystemIterator($pluginsDirectory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || Filesystem::isLink($entry->getPathname())) {
                continue;
            }
            $path = $entry->getPathname();
            try {
                if ($entry->isDir() && is_file($path . DIRECTORY_SEPARATOR . 'plugin.yml')) {
                    $name = $this->manifestReader->read($path . DIRECTORY_SEPARATOR . 'plugin.yml')->description->getName();
                    $folders[strtolower($name)][] = $path;
                } elseif ($entry->isFile() && strtolower($entry->getExtension()) === 'phar') {
                    $phar = new Phar($path);
                    if (!isset($phar['plugin.yml'])) {
                        continue;
                    }
                    $name = $this->manifestReader->read('phar://' . str_replace('\\', '/', realpath($path) ?: $path) . '/plugin.yml')->description->getName();
                    $phars[strtolower($name)][] = $path;
                }
            } catch (Throwable) {
                // Invalid entries are diagnosed by the registered loader.
            }
        }

        $warnings = [];
        foreach (array_intersect_key($folders, $phars) as $key => $folderPaths) {
            $name = $key;
            foreach ($folderPaths as $folder) {
                foreach ($phars[$key] as $phar) {
                    $warnings[] = "Plugin {$name} is present as folder {$folder} and PHAR {$phar}. DevTools prefers the development folder; verify the server startup log because Axolotl-PM's public loader API does not expose a way to reorder its built-in PHAR loader.";
                }
            }
        }

        return $warnings;
    }

    /**
     * @param list<string> $virionAntigens
     * @param list<string> $virionClasses
     * @return list<string>
     */
    public function detectVirionConflicts(string $pluginRoot, array $virionAntigens, array $virionClasses): array
    {
        $sourceRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'src';
        if (!is_dir($sourceRoot)) {
            return [];
        }
        $antigens = [];
        foreach ($virionAntigens as $antigen) {
            $antigens[strtolower(trim($antigen, '\\'))] = trim($antigen, '\\');
        }
        $classes = [];
        foreach ($virionClasses as $class) {
            $classes[strtolower(trim($class, '\\'))] = trim($class, '\\');
        }

        $conflicts = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile() || !$this->isPhpSourceFile($entry->getFilename())) {
                continue;
            }
            $symbols = $this->sourceInspector->inspect($entry->getPathname());
            foreach (array_merge($symbols->classes, $symbols->functions, $symbols->constants) as $declaration) {
                $key = strtolower(trim($declaration, '\\'));
                if (isset($classes[$key])) {
                    $conflicts[] = "Plugin declaration {$declaration} shadows shared virion class {$classes[$key]}.";
                    continue;
                }
                foreach ($antigens as $antigenKey => $antigen) {
                    if ($this->isNamespaceMember($declaration, $antigenKey)) {
                        $conflicts[] = "Plugin declaration {$declaration} overlaps shared virion antigen {$antigen}.";
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($conflicts));
    }

    private function isNamespaceMember(string $candidate, string $prefix): bool
    {
        $candidate = strtolower(trim($candidate, '\\'));
        $prefix = strtolower(trim($prefix, '\\'));

        return $candidate === $prefix || str_starts_with($candidate, $prefix . '\\');
    }

    private function isPhpSourceFile(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['php', 'inc', 'phtml'], true);
    }
}
