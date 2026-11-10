<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use function bin2hex;
use function random_bytes;

final class RandomToken
{
	private const PATH_TOKEN_BYTES = 8;
	private const SNAPSHOT_TOKEN_BYTES = 12;

	public static function pathSuffix() : string
	{
		return bin2hex(random_bytes(self::PATH_TOKEN_BYTES));
	}

	public static function snapshotSuffix() : string
	{
		return bin2hex(random_bytes(self::SNAPSHOT_TOKEN_BYTES));
	}
}
