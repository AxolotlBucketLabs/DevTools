<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Loader;

use function basename;

use Closure;

use function implode;
use function is_dir;
use function is_file;

use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Validation\PluginProjectValidator;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginDescriptionParseException;
use pocketmine\plugin\PluginLoader;
use pocketmine\thread\ThreadSafeClassLoader;

use function realpath;

use RuntimeException;

use function str_replace;
use function strtolower;

final class FolderPluginLoader implements PluginLoader
{
    /** @var array<string, true> */
    private array $registeredPaths = [];

    /** @param (Closure(string): list<string>)|null $virionConflictChecker */
    public function __construct(
        private readonly ThreadSafeClassLoader $classLoader,
        private readonly PluginManifestReader $manifestReader,
        private readonly PluginProjectValidator $validator,
        ?string $excludedProject = null,
        private readonly ?Closure $virionConflictChecker = null,
    ) {
        $this->excludedProject = $excludedProject === null ? null : self::normalizePath($excludedProject);
    }

    private readonly ?string $excludedProject;

    public function canLoadPlugin(string $path): bool
    {
        if (!is_dir($path) || Filesystem::isLink($path) || Path::isExcluded(basename($path)) || $this->isExcludedProject($path)) {
            return false;
        }

        return is_file($path . DIRECTORY_SEPARATOR . 'plugin.yml') || is_dir($path . DIRECTORY_SEPARATOR . 'src');
    }

    public function loadPlugin(string $file): void
    {
        if ($this->isExcludedProject($file)) {
            return;
        }

        $root = realpath($file);
        if ($root === false) {
            throw new RuntimeException("Cannot load folder plugin because its path cannot be resolved: {$file}");
        }
        $normalized = str_replace('\\', '/', $root);
        if (isset($this->registeredPaths[$normalized])) {
            return;
        }

        $validation = $this->validator->validate($root);
        if ($validation->hasErrors()) {
            $messages = [];
            foreach ($validation->issues() as $issue) {
                if ($issue->severity->value === 'error') {
                    $messages[] = $issue->format();
                }
            }
            throw new RuntimeException("Cannot load folder plugin at {$root}.\n\n" . implode("\n\n", $messages));
        }

        $manifest = $this->manifestReader->read($root . DIRECTORY_SEPARATOR . 'plugin.yml');
        if ($this->virionConflictChecker !== null) {
            $conflicts = ($this->virionConflictChecker)($root);
            if ($conflicts !== []) {
                throw new RuntimeException(
                    "Cannot load folder plugin at {$root}: it shadows a loaded shared virion.\n- " . implode("\n- ", $conflicts),
                );
            }
        }
        $this->classLoader->addPath(
            $manifest->description->getSrcNamespacePrefix(),
            $root . DIRECTORY_SEPARATOR . 'src',
        );
        $this->registeredPaths[$normalized] = true;
    }

    public function getPluginDescription(string $file): ?PluginDescription
    {
        if (!$this->canLoadPlugin($file)) {
            return null;
        }
        if (!is_file($file . DIRECTORY_SEPARATOR . 'plugin.yml')) {
            throw new PluginDescriptionParseException(
                "This folder contains src/ and looks like a plugin, but plugin.yml is missing.\n"
                . "File: {$file}" . DIRECTORY_SEPARATOR . "plugin.yml\n"
                . 'Next: Create plugin.yml with name, version, main, and api fields.',
            );
        }
        $validation = $this->validator->validate($file);
        if ($validation->hasErrors()) {
            $messages = [];
            foreach ($validation->issues() as $issue) {
                if ($issue->severity->value === 'error') {
                    $messages[] = $issue->format();
                }
            }
            throw new PluginDescriptionParseException(implode("\n\n", $messages));
        }

        return $this->manifestReader->read($file . DIRECTORY_SEPARATOR . 'plugin.yml')->description;
    }

    public function getAccessProtocol(): string
    {
        return '';
    }

    private function isExcludedProject(string $path): bool
    {
        if ($this->excludedProject === null) {
            return false;
        }

        $resolved = realpath($path);

        return $resolved !== false && self::normalizePath($resolved) === $this->excludedProject;
    }

    private static function normalizePath(string $path): string
    {
        $resolved = realpath($path) ?: $path;
        $normalized = str_replace('\\', '/', $resolved);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
