<?php

declare(strict_types=1);

namespace DevToolsExample\SharedGreeting;

final class Greeting
{
	public static function message() : string
	{
		return 'Hello from one shared development virion.';
	}
}
