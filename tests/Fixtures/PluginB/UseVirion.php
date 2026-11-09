<?php

declare(strict_types=1);

namespace Fixture\PluginB;

use Shared\Virion\Greeting;

final class UseVirion
{
	public static function message() : string
	{
		return Greeting::message();
	}
}
