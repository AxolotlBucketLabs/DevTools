<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

final class VirionManifest
{
	/**
	 * @param list<string>            $api
	 * @param list<string>            $php
	 * @param array<string, mixed>    $raw
	 * @param list<VirionRequirement> $requirements
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $version,
		public readonly string $antigen,
		public readonly array $api,
		public readonly array $php,
		public readonly array $raw,
		public readonly string $path,
		public readonly array $requirements = [],
	) {}
}
