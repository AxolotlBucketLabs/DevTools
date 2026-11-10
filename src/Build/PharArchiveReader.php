<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use Phar;
use PharFileInfo;
use RecursiveIteratorIterator;
use Throwable;
use function fclose;
use function fopen;
use function is_resource;
use function is_string;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;

final class PharArchiveReader implements ArchiveReader
{
	private Phar $phar;
	private readonly string $snapshotPath;

	public function __construct(string $path)
	{
		$snapshot = PharSnapshot::create($path);
		try {
			$this->phar = new Phar($snapshot);
			$this->snapshotPath = $snapshot;
		} catch (Throwable $error) {
			PharSnapshot::remove($snapshot);
			throw new BuildException("Cannot open PHAR {$path}: {$error->getMessage()}", 0, $error);
		}
	}

	public function __destruct()
	{
		unset($this->phar);
		PharSnapshot::remove($this->snapshotPath);
	}

	public function entries() : iterable
	{
		$entryCount = 0;
		$totalSizeBytes = 0;
		$iterator = new RecursiveIteratorIterator($this->phar);
		foreach ($iterator as $entry) {
			if (!$entry instanceof PharFileInfo) {
				continue;
			}
			if (!$entry->isFile()) {
				continue;
			}
			$relative = $entry->getPathName();
			$prefix = 'phar://' . $this->phar->getPath() . '/';
			if (str_starts_with($relative, $prefix)) {
				$relative = substr($relative, strlen($prefix));
			}
			ArchiveLimits::assertEntryWithinLimits($entryCount, $totalSizeBytes, $relative, $entry->getSize());
			$stream = fopen($entry->getPathName(), 'rb');
			if (!is_resource($stream)) {
				throw new BuildException("Cannot read archive entry: {$relative}");
			}
			try {
				$contents = stream_get_contents($stream, ArchiveLimits::MAX_ENTRY_SIZE_BYTES + 1);
			} finally {
				fclose($stream);
			}
			if (!is_string($contents)) {
				throw new BuildException("Cannot read archive entry: {$relative}");
			}
			$entrySizeBytes = strlen($contents);
			ArchiveLimits::assertEntryWithinLimits($entryCount, $totalSizeBytes, $relative, $entrySizeBytes);
			++$entryCount;
			$totalSizeBytes += $entrySizeBytes;
			yield new ArchiveEntry($relative, $contents, $entry->isLink());
		}
	}
}
