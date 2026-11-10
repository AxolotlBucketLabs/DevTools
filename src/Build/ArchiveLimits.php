<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

final class ArchiveLimits
{
	public const MAX_ENTRIES = 10_000;
	public const MAX_ENTRY_SIZE_BYTES = 67_108_864;
	public const MAX_TOTAL_SIZE_BYTES = 268_435_456;

	public static function assertEntryWithinLimits(int $entryCount, int $totalSizeBytes, string $path, int $entrySizeBytes) : void
	{
		if ($entryCount >= self::MAX_ENTRIES) {
			throw new BuildException("Archive exceeds the maximum of " . self::MAX_ENTRIES . " files.");
		}
		if ($entrySizeBytes < 0 || $entrySizeBytes > self::MAX_ENTRY_SIZE_BYTES) {
			throw new BuildException("Archive entry exceeds the maximum file size of " . self::MAX_ENTRY_SIZE_BYTES . " bytes: {$path}");
		}
		if ($entrySizeBytes > self::MAX_TOTAL_SIZE_BYTES - $totalSizeBytes) {
			throw new BuildException("Archive exceeds the maximum uncompressed size of " . self::MAX_TOTAL_SIZE_BYTES . " bytes.");
		}
	}
}
