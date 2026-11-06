<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;

use function file_get_contents;

use FilesystemIterator;

use function in_array;
use function is_dir;
use function is_file;
use function ltrim;

use ParseError;

use function pathinfo;
use function realpath;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function token_get_all;

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
            if (!$entry->isFile() || !$this->isPhpSourceFile($entry->getFilename())) {
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

    private function isPhpSourceFile(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['php', 'inc', 'phtml'], true);
    }

    private function isAutoloadShim(string $expectedPath, string $actualPath): bool
    {
        $contents = file_get_contents($expectedPath);
        if ($contents === false || $this->sourceInspector->inspect($expectedPath)->classes !== []) {
            return false;
        }
        $relative = null;
        try {
            $relative = $this->readRequireOncePath(token_get_all($contents, TOKEN_PARSE));
        } catch (ParseError) {
            return false;
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

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function readRequireOncePath(array $tokens): ?string
    {
        $braceDepth = 0;
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if ($token === '{') {
                    ++$braceDepth;
                } elseif ($token === '}') {
                    --$braceDepth;
                }
                continue;
            }
            if ($token[0] !== T_REQUIRE_ONCE || $braceDepth !== 0) {
                continue;
            }

            $cursor = $index;
            $dir = $this->nextSignificantToken($tokens, $cursor);
            $dot = $this->nextSignificantToken($tokens, $cursor);
            $first = $this->nextSignificantToken($tokens, $cursor);
            if (!is_array($dir) || $dir[0] !== T_DIR || $dot !== '.'
                || !is_array($first) || $first[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $relative = $this->decodeStringToken($first);
            $separator = $this->nextSignificantToken($tokens, $cursor);
            if ($separator === '.') {
                $second = $this->nextSignificantToken($tokens, $cursor);
                if (!is_array($second) || $second[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $relative .= $this->decodeStringToken($second);
            } elseif ($separator !== ';') {
                continue;
            }
            if ($separator === '.') {
                $terminator = $this->nextSignificantToken($tokens, $cursor);
                if ($terminator !== ';') {
                    continue;
                }
            }

            return $relative;
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return array{int, string, int}|string|null
     */
    private function nextSignificantToken(array $tokens, int &$index): array|string|null
    {
        $count = count($tokens);
        for (++$index; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @param array{int, string, int} $token */
    private function decodeStringToken(array $token): string
    {
        $raw = $token[1];
        $quote = $raw[0] ?? '';

        return $this->sourceInspector->decodeStringLiteral($raw, $quote);
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
