<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;

final class BuildConfig
{
	/**
	 * @param list<VirionRequirement> $virions
	 * @param list<string>            $includePaths
	 */
	public function __construct(
		public readonly array $virions,
		public readonly array $includePaths,
	) {}
}
