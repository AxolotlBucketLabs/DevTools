<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use Phar;
use function copy;
use function file_exists;
use function is_file;
use function sys_get_temp_dir;
use function unlink;
use const DIRECTORY_SEPARATOR;

final class PharSnapshot
{
	public static function create(string $source) : string
	{
		$snapshot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devtools-phar-' . RandomToken::snapshotSuffix() . ArtifactFile::PHAR_EXTENSION;
		if (!copy($source, $snapshot)) {
			throw new BuildException("Cannot snapshot PHAR {$source}.");
		}

		return $snapshot;
	}

	public static function remove(string $snapshot) : bool
	{
		try {
			Phar::unlinkArchive($snapshot);
		} catch (\Throwable) {
			// Fall back to the regular filesystem cleanup when the archive was never opened.
		}
		if (!file_exists($snapshot)) {
			return true;
		}

		return is_file($snapshot) && unlink($snapshot);
	}
}
