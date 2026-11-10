<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use PharData;
use Throwable;
use function array_map;
use function basename;
use function count;
use function dirname;
use function file_exists;
use function is_file;
use function pathinfo;
use function realpath;
use function rename;
use function strlen;
use function strtolower;
use function unlink;
use const PATHINFO_EXTENSION;

final class ZipArchiveWriter
{
	public function __construct(private readonly Filesystem $filesystem) {}

	public function write(ArchiveReader $archive, string $output, bool $overwrite = false) : int
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
		if ('.' . strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== ArtifactFile::ZIP_EXTENSION) {
			throw new BuildException("ZIP output must use a .zip extension: {$output}");
		}

		$entries = [];
		$entryCount = 0;
		$totalSizeBytes = 0;
		foreach ($archive->entries() as $entry) {
			if ($entry->symbolicLink) {
				throw new BuildException("Refusing to archive symbolic link: {$entry->path}");
			}
			try {
				$relative = Path::normalizeRelative($entry->path);
			} catch (Throwable $error) {
				throw new BuildException("Refusing unsafe archive entry {$entry->path}: {$error->getMessage()}", 0, $error);
			}
			$entrySizeBytes = strlen($entry->contents);
			ArchiveLimits::assertEntryWithinLimits($entryCount, $totalSizeBytes, $relative, $entrySizeBytes);
			++$entryCount;
			$totalSizeBytes += $entrySizeBytes;
			$entries[] = [$relative, $entry->contents];
		}
		try {
			Path::assertPortablePaths(array_map(static fn(array $entry) : string => $entry[0], $entries));
		} catch (\RuntimeException $error) {
			throw new BuildException($error->getMessage(), 0, $error);
		}

		$nonce = RandomToken::pathSuffix();
		$temporary = Path::join($realParent, '.' . basename($output) . '.' . $nonce . '.tmp' . ArtifactFile::ZIP_EXTENSION);
		$backup = null;
		try {
			$zip = new PharData($temporary);
			foreach ($entries as [$relative, $contents]) {
				$zip[$relative] = $contents;
			}
			unset($zip);
			if (file_exists($output)) {
				$backup = $output . '.backup-' . RandomToken::pathSuffix();
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
