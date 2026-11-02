<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Loader;

use FilesystemIterator;

use function is_dir;
use function is_file;

use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use Phar;
use SplFileInfo;

use function strtolower;

use Throwable;

final class PluginSourceConflictDetector
{
    public function __construct(private readonly PluginManifestReader $manifestReader) {}

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
}
