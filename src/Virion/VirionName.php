<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use function strtolower;

final class VirionName
{
	public static function key(string $name) : string
	{
		return 'virion:' . strtolower($name);
	}
}
