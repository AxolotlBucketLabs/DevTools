<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\FilesystemException;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use function array_map;
use function array_pop;
use function basename;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function realpath;
use function rename;
use function str_contains;
use function str_replace;
use function strlen;

final class PharExtractor
{
	public function __construct(
		private readonly Filesystem $filesystem,
		private readonly ?DebugLogger $debug = null,
	) {}

	public function extract(ArchiveReader $archive, string $destination, bool $overwrite = false) : int
	{
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_ARCHIVE_EXTRACTION_STARTED, ['path' => $destination]);
		if (Filesystem::isLink($destination)) {
			throw new BuildException("Refusing symbolic-link extraction destination: {$destination}");
		}
		if (file_exists($destination) && !is_dir($destination)) {
			throw new BuildException("Extraction destination is not a directory: {$destination}");
		}
		$destinationName = basename($destination);
		try {
			$normalizedName = Path::normalizeRelative($destinationName);
		} catch (\RuntimeException $error) {
			throw new BuildException("Refusing unsafe extraction destination {$destination}: {$error->getMessage()}", 0, $error);
		}
		if ($normalizedName !== $destinationName || str_contains($normalizedName, '/')) {
			throw new BuildException("Extraction destination must end in one safe directory name: {$destination}");
		}
		$parent = dirname($destination);
		$this->filesystem->ensureDirectory($parent);
		$realParent = realpath($parent);
		if ($realParent === false) {
			throw new BuildException("Cannot resolve extraction destination parent: {$parent}");
		}
		$base = Path::join($realParent, $destinationName);
		if (!Path::isInside($realParent, $base)) {
			throw new BuildException("Extraction destination escapes its parent: {$destination}");
		}

		/** @var list<array{ArchiveEntry, string, string}> $pending */
		$pending = [];
		$entryCount = 0;
		$totalSizeBytes = 0;
		foreach ($archive->entries() as $entry) {
			if ($entry->symbolicLink) {
				throw new BuildException("Refusing to extract symbolic link: {$entry->path}");
			}
			try {
				$relative = Path::normalizeRelative($entry->path);
			} catch (\RuntimeException $error) {
				throw new BuildException("Refusing unsafe archive entry {$entry->path}: {$error->getMessage()}", 0, $error);
			}
			$entrySizeBytes = strlen($entry->contents);
			ArchiveLimits::assertEntryWithinLimits($entryCount, $totalSizeBytes, $relative, $entrySizeBytes);
			++$entryCount;
			$totalSizeBytes += $entrySizeBytes;
			$target = Path::join($base, $relative);
			if (!Path::isInside($base, $target)) {
				throw new BuildException("Refusing archive entry outside destination: {$entry->path}");
			}
			$this->assertNoSymlinkParent($base, $relative);
			if (file_exists($target) && !$overwrite) {
				throw new BuildException("Refusing to overwrite existing extracted file: {$target}");
			}
			if (is_dir($target)) {
				throw new BuildException("Cannot replace an extraction directory with a file: {$target}");
			}
			$pending[] = [$entry, $relative, $target];
		}
		try {
			Path::assertPortablePaths(array_map(static fn(array $item) : string => $item[1], $pending));
		} catch (FilesystemException $error) {
			throw new BuildException('Archive contains non-portable paths: ' . $error->getMessage(), 0, $error);
		}

		$nonce = RandomToken::pathSuffix();
		$stage = Path::join($realParent, '.devtools-extract-' . $nonce);
		$backup = null;
		$this->filesystem->ensureDirectory($stage);
		try {
			if (is_dir($base)) {
				$this->filesystem->copyTree($base, $stage, null, true);
				$this->filesystem->copyPermissions($base, $stage);
			}
			foreach ($pending as [$entry, $relative]) {
				$target = Path::join($stage, $relative);
				$this->filesystem->ensureDirectory(dirname($target));
				if (file_put_contents($target, $entry->contents) === false) {
					throw new BuildException("Cannot write extracted file: {$target}");
				}
			}
			$this->filesystem->assertPortableTree($stage);
			if (is_dir($base)) {
				$backup = Path::join($realParent, '.devtools-extract-backup-' . $nonce);
				if (!rename($base, $backup)) {
					throw new BuildException("Cannot move the existing extraction destination aside: {$base}");
				}
			}
			if (!rename($stage, $base)) {
				if ($backup !== null && !rename($backup, $base)) {
					throw new BuildException("Cannot install extracted files. The previous destination remains at {$backup}.");
				}
				throw new BuildException("Cannot install extracted files at {$base}.");
			}
			if ($backup !== null) {
				$this->filesystem->removeTree($backup);
			}
		} catch (FilesystemException $error) {
			throw new BuildException($error->getMessage(), 0, $error);
		} finally {
			if (file_exists($stage) || Filesystem::isLink($stage)) {
				$this->filesystem->removeTree($stage);
			}
		}

		$count = count($pending);
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_ARCHIVE_FILES_EXTRACTED, ['count' => $count, 'path' => $base]);

		return $count;
	}

	private function assertNoSymlinkParent(string $base, string $relative) : void
	{
		$components = explode('/', str_replace('\\', '/', $relative));
		array_pop($components);
		$current = $base;
		foreach ($components as $component) {
			$current = Path::join($current, $component);
			if (Filesystem::isLink($current)) {
				throw new BuildException("Refusing extraction through symbolic-link directory: {$current}");
			}
			if (file_exists($current) && !is_dir($current)) {
				throw new BuildException("Cannot create extraction directory because a file exists: {$current}");
			}
		}
	}
}
