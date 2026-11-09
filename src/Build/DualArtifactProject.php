<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

final class DualArtifactProject
{
	public function __construct(
		public readonly string $root,
		public readonly string $pluginName,
		public readonly string $virionName,
	) {}
}
