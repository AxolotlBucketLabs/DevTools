<?php

declare(strict_types=1);

namespace RuntimeSmoke\{{PLUGIN}};

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use RuntimeSmoke\Library\Value;

final class Main extends PluginBase
{
    protected function onEnable(): void
    {
        if ($this->getName() === 'SmokeA' && !$this->getServer()->getPluginManager()->isPluginEnabled($this->getServer()->getPluginManager()->getPlugin('SmokeB'))) {
            throw new \RuntimeException('Required plugin was not enabled first');
        }
        ++Value::$counter;
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function (): void {
            $other = $this->getServer()->getPluginManager()->getPlugin('{{OTHER}}');
            $shared = getenv('DEVTOOLS_SMOKE_MODE') === 'shared';
            $accepts = false;
            try {
                $accepts = $this->accept($other->value());
            } catch (\TypeError) {
            }
            if (Value::$counter !== ($shared ? 2 : 1) || $accepts !== $shared || Value::version() !== '{{VERSION}}') {
                throw new \RuntimeException('Shared/private type or static-state contract failed');
            }
            $resource = $this->getResource('probe.txt');
            if (!is_resource($resource) || stream_get_contents($resource) !== 'resource-ok') {
                throw new \RuntimeException('Plugin resource missing');
            }
            fclose($resource);
            $this->getServer()->getAsyncPool()->submitTask(new AsyncProbe());
        }), 2);
    }

    public function value(): Value
    {
        return new Value();
    }

    public function accept(Value $value): bool
    {
        return true;
    }

    public static function completed(mixed $result): void
    {
        $shared = getenv('DEVTOOLS_SMOKE_MODE') === 'shared';
        if ($result !== '{{VERSION}}:1' || Value::$counter !== ($shared ? 2 : 1)) {
            throw new \RuntimeException('Async virion resolution or thread-local static state failed');
        }
        $server = Server::getInstance();
        if (!$shared && $server->getPluginManager()->getPlugin('DevTools') !== null) {
            throw new \RuntimeException('Clean PHAR server unexpectedly contains DevTools');
        }
        file_put_contents($server->getDataPath() . '{{PLUGIN}}.passed', 'passed');
        $server->getLogger()->info('DEVTOOLS_RUNTIME_PASS {{PLUGIN}} version={{VERSION}} async=passed');
        if (is_file($server->getDataPath() . '{{OTHER}}.passed')) {
            $server->shutdown();
        }
    }
}
