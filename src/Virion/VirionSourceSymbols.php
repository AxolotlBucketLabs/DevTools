<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

final class VirionSourceSymbols
{
	/**
	 * @param list<string> $classes
	 * @param list<string> $functions
	 * @param list<string> $constants
	 * @param list<string> $unconditionalFunctions
	 */
	public function __construct(
		public readonly array $classes,
		public readonly array $functions,
		public readonly array $constants,
		public readonly array $unconditionalFunctions,
	) {}
}
