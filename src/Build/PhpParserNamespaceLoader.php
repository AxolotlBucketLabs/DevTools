<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function bin2hex;

use Closure;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match_all;
use function random_bytes;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rmdir;

use RuntimeException;
use SplFileInfo;

use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

final class PhpParserNamespaceLoader
{
    private const PREFIX = 'NhanAZ\\DevTools\\Vendor\\PhpParser\\';

    /** @var (Closure(string): void)|null */
    private static ?Closure $autoloader = null;

    private static ?string $sourceRoot = null;

    private static ?string $temporaryRoot = null;

    public static function register(string $sourceRoot): void
    {
        $sourceRoot = rtrim($sourceRoot, '/\\');
        if (self::$autoloader !== null && self::$sourceRoot === $sourceRoot) {
            return;
        }
        self::unregister();
        $temporaryRoot = null;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $candidate = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devtools-parser-' . bin2hex(random_bytes(12));
            if (mkdir($candidate, 0700)) {
                $temporaryRoot = $candidate;
                break;
            }
        }
        if ($temporaryRoot === null) {
            throw new RuntimeException('Cannot create an isolated PHP-Parser directory.');
        }
        $loader = static function (string $class) use ($sourceRoot, $temporaryRoot): void {
            if (!str_starts_with($class, self::PREFIX)) {
                return;
            }
            $relative = substr($class, strlen(self::PREFIX));
            if ($relative === '') {
                return;
            }
            $relative = str_replace('\\', '/', $relative);
            if (!is_file($sourceRoot . '/' . $relative . '.php')) {
                return;
            }
            self::prepareSource($sourceRoot, self::$temporaryRoot ?? $temporaryRoot, $relative);
            require($temporaryRoot . DIRECTORY_SEPARATOR . $relative . '.php');
        };
        self::$sourceRoot = $sourceRoot;
        self::$temporaryRoot = $temporaryRoot;
        self::$autoloader = $loader;
        spl_autoload_register($loader, true, true);
    }

    public static function unregister(): void
    {
        if (self::$autoloader !== null) {
            spl_autoload_unregister(self::$autoloader);
            self::$autoloader = null;
            self::$sourceRoot = null;
            if (self::$temporaryRoot !== null) {
                self::removeTree(self::$temporaryRoot);
                self::$temporaryRoot = null;
            }
        }
    }

    private static function prepareSource(string $sourceRoot, string $temporaryRoot, string $relative): void
    {
        $sourcePath = $sourceRoot . '/' . $relative . '.php';
        $temporaryPath = $temporaryRoot . '/' . $relative . '.php';
        if (is_file($temporaryPath)) {
            return;
        }
        if (!is_file($sourcePath)) {
            return;
        }
        $source = file_get_contents($sourcePath);
        if ($source === false) {
            throw new RuntimeException("Cannot read isolated PHP-Parser source {$sourcePath}.");
        }
        $requires = [];
        if (preg_match_all('~require(?:_once)?\\s+__DIR__\\s*\\.\\s*[\\x27\\x22]([^\\x27\\x22]+)[\\x27\\x22]~', $source, $matches) !== false) {
            $base = str_replace('\\', '/', dirname($relative));
            foreach ($matches[1] as $required) {
                $required = str_replace('\\', '/', (string) $required);
                if (str_ends_with($required, '.php')) {
                    $required = substr($required, 0, -4);
                }
                $requires[] = self::resolveRelative($base, $required);
            }
        }
        foreach ($requires as $required) {
            self::prepareSource($sourceRoot, $temporaryRoot, $required);
        }
        $source = str_replace(
            ['namespace PhpParser;', 'use PhpParser;', 'PhpParser\\'],
            ['namespace ' . rtrim(self::PREFIX, '\\') . ';', 'use ' . rtrim(self::PREFIX, '\\') . ';', self::PREFIX],
            $source,
        );
        $parent = dirname($temporaryPath);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
            throw new RuntimeException("Cannot create isolated PHP-Parser directory {$parent}.");
        }
        if (file_put_contents($temporaryPath, $source) === false) {
            throw new RuntimeException("Cannot prepare isolated PHP-Parser source {$sourcePath}.");
        }
    }

    private static function resolveRelative(string $base, string $path): string
    {
        $parts = [];
        foreach (explode('/', trim($base . '/' . str_replace('\\', '/', $path), '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
