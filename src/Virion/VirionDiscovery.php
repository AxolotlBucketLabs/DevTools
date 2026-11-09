<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use FilesystemIterator;
use SplFileInfo;
use function sort;
use function str_ends_with;
use function strtolower;
use const SORT_STRING;

final class VirionDiscovery
{
	public function __construct(private readonly Filesystem $filesystem) {}

	/** @return list<string> */
	public function discover(string $directory) : array
	{
		$this->filesystem->ensureDirectory($directory);
		$locations = [];
		$iterator = new FilesystemIterator(
			$directory,
			FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
		);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo) {
				continue;
			}
			if (Path::isExcluded($entry->getFilename()) || Filesystem::isLink($entry->getPathname())) {
				continue;
			}
			if ($entry->isDir() || ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.phar'))) {
				$locations[] = $entry->getPathname();
			}
		}
		sort($locations, SORT_STRING);

		return $locations;
	}
}
