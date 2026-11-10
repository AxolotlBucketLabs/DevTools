<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\HashAlgorithm;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function hash;
use function hash_file;
use function json_encode;
use function ksort;
use function str_replace;
use function strlen;
use function substr;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SORT_STRING;

final class VirionSourceFingerprint
{
	public function hash(string $source) : string
	{
		if (Filesystem::isLink($source)) {
			throw new VirionException("Refusing symbolic link in prepared source {$source}.");
		}
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo) {
				continue;
			}
			if ($entry->isLink() || Filesystem::isLink($entry->getPathname())) {
				throw new VirionException("Refusing symbolic link in prepared source {$entry->getPathname()}.");
			}
			if ($entry->isFile()) {
				$hash = hash_file(HashAlgorithm::SHA256, $entry->getPathname());
				if ($hash === false) {
					throw new VirionException("Cannot hash prepared source {$entry->getPathname()}.");
				}
				$files[str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1))] = $hash;
			}
		}
		ksort($files, SORT_STRING);
		return hash(HashAlgorithm::SHA256, json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}
}
