<?php

declare(strict_types=1);

namespace Fixture\Build;

use pocketmine\plugin\PluginBase;
use Shared\Virion\Greeting;

final class Main extends PluginBase
{
	public function greeting() : string
	{
		return Greeting::message();
	}
}
