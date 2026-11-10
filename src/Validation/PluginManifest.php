<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use pocketmine\plugin\PluginDescription;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final class PluginManifest
{
	/**
	 * @param array<string, mixed> $raw
	 */
	public function __construct(
		public readonly PluginDescription $description,
		public readonly array $raw,
		public readonly string $path,
	) {}

	public function expectedMainRelativePath() : ?string
	{
		$main = trim($this->description->getMain(), '\\');
		$prefix = trim($this->description->getSrcNamespacePrefix(), '\\');
		if ($prefix !== '') {
			if (!str_starts_with($main, $prefix . '\\')) {
				return null;
			}
			$main = substr($main, strlen($prefix) + 1);
		}

		return str_replace('\\', '/', $main) . ArtifactFile::PHP_SOURCE_EXTENSION;
	}
}
