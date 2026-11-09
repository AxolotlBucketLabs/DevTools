<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

final class ArchiveEntry
{
	public function __construct(
		public readonly string $path,
		public readonly string $contents,
		public readonly bool $symbolicLink = false,
	) {}
}
