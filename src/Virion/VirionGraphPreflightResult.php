<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

final class VirionGraphPreflightResult
{
	/**
	 * @param array<string, true>       $readyProjects
	 * @param list<VirionRegistryEntry> $failures
	 */
	public function __construct(
		public readonly array $readyProjects,
		public readonly array $failures,
	) {}
}
