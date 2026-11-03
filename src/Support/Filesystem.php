<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Support;

use function copy;
use function dirname;
use function file_exists;

use FilesystemIterator;

use function is_dir;
use function is_file;
use function is_link;
use function mkdir;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rmdir;

use SplFileInfo;

use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function unlink;

final class Filesystem
{
    public static function isLink(string $path): bool
    {
        $path = rtrim($path, '/\\');
        if (is_link($path)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows' || $path === '' || preg_match('/^[A-Za-z]:$/D', $path) === 1
            || in_array(basename($path), ['.', '..'], true)) {
            return false;
        }

        // Windows junctions resolve to another directory but are not reported by is_link().
        $resolved = realpath($path);
        $parent = realpath(dirname($path));
        if ($resolved === false || $parent === false
            || strcasecmp($resolved, Path::join($parent, basename($path))) === 0) {
            return false;
        }

        // An NTFS short name still identifies the same entry; a junction has its own identity.
        $entry = lstat($path);
        $target = lstat($resolved);
        return $entry === false || $target === false || $entry['ino'] === 0
            || $entry['ino'] !== $target['ino'] || $entry['dev'] !== $target['dev'];
    }

    public function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (file_exists($path) || !mkdir($path, 0777, true)) {
            throw new FilesystemException("Cannot create directory: {$path}");
        }
    }

    /**
     * @param callable(string): bool|null $include Receives a normalized relative path.
     */
    public function copyTree(string $source, string $destination, ?callable $include = null): void
    {
        if (self::isLink($source)) {
            throw new FilesystemException("Refusing to copy symbolic-link directory: {$source}");
        }
        if (!is_dir($source)) {
            throw new FilesystemException("Source directory does not exist: {$source}");
        }

        $this->ensureDirectory($destination);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $seen = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $relative = $this->portableRelative($source, $entry->getPathname());
            if (self::isLink($entry->getPathname())) {
                throw new FilesystemException("Refusing to copy symbolic link: {$entry->getPathname()}");
            }
            if ($include !== null && !$include($relative)) {
                continue;
            }
            $this->recordPortablePath($seen, $relative, $entry->isDir());
            $target = Path::join($destination, $relative);
            if ($entry->isDir()) {
                $this->ensureDirectory($target);
            } elseif ($entry->isFile()) {
                $this->ensureDirectory(dirname($target));
                if (!copy($entry->getPathname(), $target)) {
                    throw new FilesystemException("Cannot copy {$entry->getPathname()} to {$target}");
                }
            }
        }
    }

    public function assertPortableTree(string $root): void
    {
        if (self::isLink($root) || !is_dir($root)) {
            throw new FilesystemException("Cannot validate a missing or symbolic-link staging directory: {$root}");
        }

        $seen = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $path = $entry->getPathname();
            if (self::isLink($path)) {
                throw new FilesystemException("Refusing to validate symbolic link: {$path}");
            }
            $relative = $this->portableRelative($root, $path);
            $this->recordPortablePath($seen, $relative, $entry->isDir());
        }
    }

    private function portableRelative(string $root, string $path): string
    {
        $relative = substr($path, strlen($root) + 1);
        if (DIRECTORY_SEPARATOR === '/' && str_contains($relative, '\\')) {
            throw new FilesystemException(
                "Cannot stage {$path}: a backslash in a POSIX filename would change meaning when normalized.",
            );
        }

        return Path::normalizeRelative(str_replace(DIRECTORY_SEPARATOR, '/', $relative));
    }

    /** @param array<string, array{path: string, directory: bool}> $seen */
    private function recordPortablePath(array &$seen, string $relative, bool $directory): void
    {
        $key = strtolower($relative);
        if (isset($seen[$key])) {
            throw new FilesystemException(
                "Cannot stage {$relative}: it is duplicate or case-conflicting with {$seen[$key]['path']}.",
            );
        }
        foreach ($seen as $seenKey => $entry) {
            if (str_starts_with($key, $seenKey . '/') && !$entry['directory']) {
                throw new FilesystemException(
                    "Cannot stage {$relative}: it conflicts with file {$entry['path']} as a file and directory path.",
                );
            }
            if (str_starts_with($seenKey, $key . '/') && !$directory) {
                throw new FilesystemException(
                    "Cannot stage {$relative}: it conflicts with {$entry['path']} as a file and directory path.",
                );
            }
        }
        $seen[$key] = ['path' => $relative, 'directory' => $directory];
    }

    public function assertNoSymbolicLinkComponents(string $base, string $relative): void
    {
        $relative = Path::normalizeRelative($relative);
        $current = $base;
        foreach (explode('/', $relative) as $component) {
            $current = Path::join($current, $component);
            if (self::isLink($current)) {
                throw new FilesystemException("Refusing path through symbolic link: {$current}");
            }
        }
    }

    public function copyFile(string $source, string $destination): void
    {
        if (!is_file($source) || self::isLink($source)) {
            throw new FilesystemException("Refusing to copy a missing file or symbolic link: {$source}");
        }
        $this->ensureDirectory(dirname($destination));
        if (!copy($source, $destination)) {
            throw new FilesystemException("Cannot copy {$source} to {$destination}");
        }
    }

    public function removeTree(string $path): void
    {
        if (!file_exists($path) && !self::isLink($path)) {
            return;
        }
        if (self::isLink($path) || is_file($path)) {
            if (!(PHP_OS_FAMILY === 'Windows' && is_dir($path) ? rmdir($path) : unlink($path))) {
                throw new FilesystemException("Cannot remove file: {$path}");
            }

            return;
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $this->removeTree($entry->getPathname());
        }
        if (!rmdir($path)) {
            throw new FilesystemException("Cannot remove directory: {$path}");
        }
    }
}
