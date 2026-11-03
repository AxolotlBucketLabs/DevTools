<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function bin2hex;
use function count;
use function file_exists;
use function is_file;

use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\Path;
use PharData;

use function random_bytes;
use function realpath;
use function rename;
use function strtolower;

use Throwable;

use function unlink;

final class ZipArchiveWriter
{
    public function __construct(private readonly Filesystem $filesystem) {}

    public function write(ArchiveReader $archive, string $output, bool $overwrite = false): int
    {
        if (Filesystem::isLink($output) || (file_exists($output) && !is_file($output))) {
            throw new BuildException("ZIP output is not a regular file: {$output}.");
        }
        if (file_exists($output) && !$overwrite) {
            throw new BuildException("Refusing to replace existing ZIP {$output}. Run again with overwrite explicitly enabled.");
        }
        $parent = dirname($output);
        $this->filesystem->ensureDirectory($parent);
        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new BuildException("Cannot resolve ZIP output directory: {$parent}");
        }
        $output = Path::join($realParent, basename($output));
        if (strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'zip') {
            throw new BuildException("ZIP output must use a .zip extension: {$output}");
        }

        $entries = [];
        $seen = [];
        foreach ($archive->entries() as $entry) {
            if ($entry->symbolicLink) {
                throw new BuildException("Refusing to archive symbolic link: {$entry->path}");
            }
            try {
                $relative = Path::normalizeRelative($entry->path);
            } catch (Throwable $error) {
                throw new BuildException("Refusing unsafe archive entry {$entry->path}: {$error->getMessage()}", 0, $error);
            }
            $key = strtolower($relative);
            if (isset($seen[$key])) {
                throw new BuildException("Archive contains duplicate or case-conflicting path: {$relative}");
            }
            foreach ($seen as $seenPath => $_) {
                if (str_starts_with($key, $seenPath . '/') || str_starts_with($seenPath, $key . '/')) {
                    throw new BuildException("Archive contains a file and directory prefix conflict: {$relative}");
                }
            }
            $seen[$key] = true;
            $entries[] = [$relative, $entry->contents];
        }
        try {
            Path::assertPortablePaths(array_map(static fn(array $entry): string => $entry[0], $entries));
        } catch (\RuntimeException $error) {
            throw new BuildException($error->getMessage(), 0, $error);
        }

        $nonce = bin2hex(random_bytes(8));
        $temporary = Path::join($realParent, '.' . basename($output) . '.' . $nonce . '.tmp.zip');
        $backup = null;
        try {
            $zip = new PharData($temporary);
            foreach ($entries as [$relative, $contents]) {
                $zip[$relative] = $contents;
            }
            unset($zip);
            if (file_exists($output)) {
                $backup = $output . '.backup-' . bin2hex(random_bytes(4));
                if (!rename($output, $backup)) {
                    throw new BuildException("Cannot move existing ZIP aside before replacement: {$output}");
                }
            }
            if (!rename($temporary, $output)) {
                if ($backup !== null && !rename($backup, $output)) {
                    throw new BuildException("Cannot install ZIP or restore the previous output. The previous output remains at {$backup}.");
                }
                throw new BuildException("Cannot install ZIP at {$output}.");
            }
            PharSnapshot::remove($temporary);
            if ($backup !== null && file_exists($backup)) {
                unlink($backup);
            }
        } finally {
            PharSnapshot::remove($temporary);
        }

        return count($entries);
    }
}
