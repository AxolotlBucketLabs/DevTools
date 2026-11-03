<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

use FilesystemIterator;
use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\PhpSourceInspector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

final class VirionClassScanner
{
    public function __construct(private readonly PhpSourceInspector $sourceInspector) {}

    /** @return list<string> */
    public function scan(VirionProject $project): array
    {
        if (Filesystem::isLink($project->sourceRoot)) {
            throw new VirionException("Cannot load {$project->manifest->name}: symbolic link found at {$project->sourceRoot}.");
        }
        $classes = [];
        /** @var array<string, list<string>> $classesByFile */
        $classesByFile = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($project->sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if ($entry->isLink() || Filesystem::isLink($entry->getPathname())) {
                throw new VirionException("Cannot load {$project->manifest->name}: symbolic link found at {$entry->getPathname()}.");
            }
            if (!$entry->isFile() || !str_ends_with(strtolower($entry->getFilename()), '.php')) {
                continue;
            }
            $symbols = $this->sourceInspector->inspect($entry->getPathname());
            foreach (array_merge($symbols->functions, $symbols->constants) as $symbol) {
                if (!$this->isNamespaceMember($symbol, $project->manifest->antigen)) {
                    throw new VirionException(
                        "Cannot load {$project->manifest->name}: declaration {$symbol} is outside antigen {$project->manifest->antigen}.",
                    );
                }
            }
            $fileClasses = $symbols->classes;
            $classesByFile[$entry->getPathname()] = $fileClasses;
            foreach ($fileClasses as $class) {
                $classes[] = $class;
            }
        }
        sort($classes, SORT_STRING);
        $classes = array_values(array_unique($classes));
        $declaredIn = [];
        foreach ($classesByFile as $file => $fileClasses) {
            foreach ($fileClasses as $class) {
                $declaredIn[$class][] = $file;
            }
        }
        foreach ($classes as $class) {
            $files = $declaredIn[$class] ?? [];
            if (count($files) > 1) {
                throw new VirionException("Cannot load {$project->manifest->name}: class {$class} is declared more than once.");
            }
            if ($class === $project->manifest->antigen) {
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: class {$class} must be below antigen {$project->manifest->antigen}, not equal to it.",
                );
            }
            if (!str_starts_with($class, $project->manifest->antigen . '\\')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($project->manifest->antigen) + 1)) . '.php';
            if (!$this->caseSensitiveFileExists($project->sourceRoot, $relative)) {
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: expected source file {$project->sourceRoot}/{$relative} for class {$class}. "
                    . 'Correct the antigen, class namespace, filename, or directory casing.',
                );
            }
            $actual = $this->relativePath($project->sourceRoot, $files[0] ?? '');
            $expectedPath = $project->sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($actual !== $relative && !$this->isAutoloadShim($expectedPath, $files[0] ?? '')) {
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: class {$class} is declared in {$actual}, expected {$relative}. "
                    . 'Move the declaration to the PSR-4 path matching its namespace.',
                );
            }
        }

        return $classes;
    }

    private function isNamespaceMember(string $candidate, string $prefix): bool
    {
        $candidate = strtolower(ltrim($candidate, '\\'));
        $prefix = strtolower(ltrim($prefix, '\\'));

        return $candidate === $prefix || str_starts_with($candidate, $prefix . '\\');
    }

    private function isAutoloadShim(string $expectedPath, string $actualPath): bool
    {
        $contents = file_get_contents($expectedPath);
        if ($contents === false || $this->sourceInspector->inspect($expectedPath)->classes !== []) {
            return false;
        }

        return str_contains($contents, 'require_once')
            && str_contains($contents, basename(str_replace('\\', '/', $actualPath)));
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = str_replace('\\', '/', $path);
        $prefix = $base . '/';
        if (strncasecmp($path, $prefix, strlen($prefix)) !== 0) {
            return $path;
        }

        return substr($path, strlen($prefix));
    }

    private function caseSensitiveFileExists(string $base, string $relative): bool
    {
        $current = $base;
        foreach (explode('/', $relative) as $component) {
            if (!is_dir($current)) {
                return false;
            }
            $entries = scandir($current);
            if ($entries === false || !in_array($component, $entries, true)) {
                return false;
            }
            $current .= DIRECTORY_SEPARATOR . $component;
        }

        return is_file($current);
    }
}
