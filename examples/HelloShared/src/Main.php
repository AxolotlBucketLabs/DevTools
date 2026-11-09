<?php

declare(strict_types=1);

namespace DevToolsExample\HelloShared;

use DevToolsExample\SharedGreeting\Greeting;
use pocketmine\plugin\PluginBase;

final class Main extends PluginBase
{
	public function onEnable() : void
	{
		$this->getLogger()->info(Greeting::message());
	}
}
