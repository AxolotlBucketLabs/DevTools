<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Loader;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use FilesystemIterator;
use SplFileInfo;
use function is_dir;
use function is_file;
use function sort;
use function str_replace;
use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

final class FolderPluginDiscovery
{
	/**
	 * Finds immediate children that either contain plugin.yml or contain src/ and therefore look like an incomplete plugin.
	 *
	 * @return list<string>
	 */
	public function discover(string $pluginsDirectory) : array
	{
		if (!is_dir($pluginsDirectory)) {
			return [];
		}

		$projects = [];
		$iterator = new FilesystemIterator(
			$pluginsDirectory,
			FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
		);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo) {
				continue;
			}
			if (!$entry->isDir() || Filesystem::isLink($entry->getPathname()) || Path::isExcluded($entry->getFilename())) {
				continue;
			}
			$path = $entry->getPathname();
			if (is_file($path . DIRECTORY_SEPARATOR . 'plugin.yml') || is_dir($path . DIRECTORY_SEPARATOR . 'src')) {
				$projects[] = str_replace('\\', '/', $path);
			}
		}
		sort($projects, SORT_STRING);

		return $projects;
	}
}
