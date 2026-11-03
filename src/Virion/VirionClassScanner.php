<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

use function file_get_contents;

use FilesystemIterator;

use function is_dir;
use function is_file;
use function ltrim;

use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Support\PhpSourceInspector;

use function preg_match;
use function realpath;

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
        return $this->scanSymbols($project)->classes;
    }

    public function scanSymbols(VirionProject $project): VirionSourceSymbols
    {
        if (Filesystem::isLink($project->sourceRoot)) {
            throw new VirionException("Cannot load {$project->manifest->name}: symbolic link found at {$project->sourceRoot}.");
        }
        $classes = [];
        $functions = [];
        $unconditionalFunctions = [];
        $constants = [];
        /** @var array<string, list<string>> $classesByFile */
        $classesByFile = [];
        /** @var array<string, list<string>> $functionsByFile */
        $functionsByFile = [];
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
                if (!$this->isNamespaceChild($symbol, $project->manifest->antigen)) {
                    throw new VirionException(
                        "Cannot load {$project->manifest->name}: declaration {$symbol} is outside antigen {$project->manifest->antigen}.",
                    );
                }
            }
            $functions = array_merge($functions, $symbols->functions);
            $unconditionalFunctions = array_merge($unconditionalFunctions, $symbols->unconditionalFunctions);
            $constants = array_merge($constants, $symbols->constants);
            $fileClasses = $symbols->classes;
            $classesByFile[$entry->getPathname()] = $fileClasses;
            $functionsByFile[$entry->getPathname()] = $symbols->functions;
            foreach ($fileClasses as $class) {
                $classes[] = $class;
            }
        }
        sort($classes, SORT_STRING);
        $classes = array_values(array_unique($classes));
        /** @var array<string, list<array{class: string, file: string}>> $declaredIn */
        $declaredIn = [];
        foreach ($classesByFile as $file => $fileClasses) {
            foreach ($fileClasses as $class) {
                $declaredIn[strtolower($class)][] = ['class' => $class, 'file' => $file];
            }
        }
        foreach ($declaredIn as $declarations) {
            if (count($declarations) > 1) {
                $names = [];
                foreach ($declarations as $declaration) {
                    if (!in_array($declaration['class'], $names, true)) {
                        $names[] = $declaration['class'];
                    }
                }
                if (count($names) === 1) {
                    throw new VirionException("Cannot load {$project->manifest->name}: class {$names[0]} is declared more than once.");
                }
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: class declarations " . implode(', ', $names) . ' differ only by case.',
                );
            }
        }
        /** @var array<string, list<array{function: string, file: string}>> $functionsDeclaredIn */
        $functionsDeclaredIn = [];
        foreach ($functionsByFile as $file => $fileFunctions) {
            foreach ($fileFunctions as $function) {
                $functionsDeclaredIn[strtolower($function)][] = ['function' => $function, 'file' => $file];
            }
        }
        foreach ($functionsDeclaredIn as $declarations) {
            if (count($declarations) <= 1) {
                continue;
            }
            $names = [];
            foreach ($declarations as $declaration) {
                if (!in_array($declaration['function'], $names, true)) {
                    $names[] = $declaration['function'];
                }
            }
            if (count($names) === 1) {
                throw new VirionException("Cannot load {$project->manifest->name}: function {$names[0]} is declared more than once.");
            }
            throw new VirionException(
                "Cannot load {$project->manifest->name}: function declarations " . implode(', ', $names) . ' differ only by case.',
            );
        }
        foreach ($classes as $class) {
            $declarations = $declaredIn[strtolower($class)] ?? [];
            $files = $declarations === [] ? [] : [$declarations[0]['file']];
            if ($class === $project->manifest->antigen) {
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: class {$class} must be below antigen {$project->manifest->antigen}, not equal to it.",
                );
            }
            if (!str_starts_with($class, $project->manifest->antigen . '\\')) {
                throw new VirionException(
                    "Cannot load {$project->manifest->name}: class {$class} is outside antigen {$project->manifest->antigen}.",
                );
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

        return new VirionSourceSymbols(
            $classes,
            array_values(array_unique($functions)),
            array_values(array_unique($constants)),
            array_values(array_unique($unconditionalFunctions)),
        );
    }

    private function isNamespaceChild(string $candidate, string $prefix): bool
    {
        $candidate = strtolower(ltrim($candidate, '\\'));
        $prefix = strtolower(ltrim($prefix, '\\'));

        return str_starts_with($candidate, $prefix . '\\');
    }

    private function isAutoloadShim(string $expectedPath, string $actualPath): bool
    {
        $contents = file_get_contents($expectedPath);
        if ($contents === false || $this->sourceInspector->inspect($expectedPath)->classes !== []) {
            return false;
        }
        $relative = null;
        if (preg_match('/\\brequire_once\\s+__DIR__\\s*\\.\\s*([\'\"])([^\'\"]+)\\1\\s*;/D', $contents, $matches) === 1) {
            $relative = $matches[2];
        } elseif (preg_match('/\\brequire_once\\s+__DIR__\\s*\\.\\s*([\'\"])([^\'\"]*)\\1\\s*\\.\\s*([\'\"])([^\'\"]+)\\3\\s*;/D', $contents, $matches) === 1) {
            $relative = $matches[2] . $matches[4];
        }
        if ($relative === null) {
            return false;
        }
        $relative = ltrim($relative, '/\\');
        try {
            $relative = Path::normalizeRelative($relative);
        } catch (\RuntimeException) {
            return false;
        }
        $target = dirname($expectedPath) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($this->isVirtualPath($target) || $this->isVirtualPath($actualPath)) {
            if (!$this->isVirtualPath($target) || !$this->isVirtualPath($actualPath)) {
                return false;
            }

            return $this->normalizeVirtualPath($target) === $this->normalizeVirtualPath($actualPath);
        }
        $targetReal = realpath($target);
        $actualReal = realpath($actualPath);
        if ($targetReal === false || $actualReal === false) {
            return false;
        }

        return $this->normalizePath($targetReal) === $this->normalizePath($actualReal);
    }

    private function isVirtualPath(string $path): bool
    {
        return str_starts_with(strtolower($path), 'phar://');
    }

    private function normalizeVirtualPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
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

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
