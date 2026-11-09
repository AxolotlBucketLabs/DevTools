<?php

declare(strict_types=1);

namespace pocketmine\plugin;

use pocketmine\utils\MainLogger;

abstract class PluginBase
{
	final public function getLogger() : MainLogger
	{
		return new MainLogger();
	}

	protected function onEnable() : void {}
}
