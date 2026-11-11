<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use function strtolower;

final class VirionName
{
	private const CURRENT_DIRECTORY = '.';
	private const PARENT_DIRECTORY = '..';

	public static function key(string $name) : string
	{
		return 'virion:' . strtolower($name);
	}

	public static function isPathComponent(string $name) : bool
	{
		return $name === self::CURRENT_DIRECTORY || $name === self::PARENT_DIRECTORY;
	}
}
