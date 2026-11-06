<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;

use function dirname;
use function is_dir;
use function realpath;
use function strtolower;

use Throwable;

final class PluginProjectLocator
{
    public function __construct(
        private readonly FolderPluginDiscovery $discovery,
        private readonly PluginManifestReader $manifestReader,
    ) {}

    public function locate(string $nameOrPath, string $pluginsDirectory): string
    {
        if (str_contains($nameOrPath, "\0")) {
            throw new BuildException('Plugin selection contains a null byte.');
        }
        $pluginsRoot = realpath($pluginsDirectory);
        if ($pluginsRoot === false) {
            throw new BuildException("Cannot resolve plugins directory {$pluginsDirectory}.");
        }
        if (is_dir($nameOrPath)) {
            if (Filesystem::isLink($nameOrPath)) {
                throw new BuildException("Refusing symbolic-link plugin project: {$nameOrPath}");
            }
            $real = realpath($nameOrPath);
            if ($real !== false) {
                if (!Path::isInside($pluginsRoot, $real) || dirname($real) !== $pluginsRoot) {
                    throw new BuildException(
                        "Refusing plugin project outside the immediate plugins directory {$pluginsRoot}: {$real}",
                    );
                }
                return $real;
            }
        }

        $matches = [];
        foreach ($this->discovery->discover($pluginsDirectory) as $project) {
            try {
                $manifest = $this->manifestReader->read($project . DIRECTORY_SEPARATOR . 'plugin.yml');
            } catch (Throwable) {
                continue;
            }
            if (strtolower($manifest->description->getName()) === strtolower($nameOrPath)) {
                $matches[] = $project;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches !== []) {
            throw new BuildException("Development plugin name \"{$nameOrPath}\" is ambiguous: " . implode(', ', $matches) . '. Select an explicit folder path under the plugins directory or remove the duplicate manifest name.');
        }

        throw new BuildException(
            "Cannot find development plugin \"{$nameOrPath}\". Use its plugin.yml name or its folder path under {$pluginsDirectory}.",
        );
    }
}
