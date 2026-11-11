<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use function preg_match;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;

final class NamespaceStringReferenceMatcher
{
	private const NAMESPACE_SEPARATOR = '\\';

	public static function contains(string $value, string $namespace) : bool
	{
		$offset = 0;
		while (($position = strpos($value, $namespace, $offset)) !== false) {
			$end = $position + strlen($namespace);
			if (self::startsAtNamespaceBoundary($value, $position)
				&& ($end === strlen($value) || $value[$end] === self::NAMESPACE_SEPARATOR)) {
				return true;
			}
			$offset = $position + 1;
		}

		return false;
	}

	private static function startsAtNamespaceBoundary(string $value, int $position) : bool
	{
		if ($position === 0) {
			return true;
		}
		$prefix = substr($value, 0, $position);
		if (str_ends_with($prefix, self::NAMESPACE_SEPARATOR)) {
			$prefix = substr($prefix, 0, -strlen(self::NAMESPACE_SEPARATOR));
		}
		if ($prefix === '') {
			return true;
		}

		return preg_match('/[\pL\pN_]\z/u', $prefix) === 0;
	}
}
