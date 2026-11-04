<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Command;

use function is_file;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use NhanAZ\DevTools\Command\BuildWorkerThread;
use NhanAZ\DevTools\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\ThreadSafeClassLoader;
use RuntimeException;

use function usleep;

#[RequiresPhpExtension('pmmpthread')]
final class BuildWorkerThreadTest extends TestCase
{
    public function test_worker_builds_without_using_the_server_async_pool(): void
    {
        $autoloadPath = defined('pocketmine\\COMPOSER_AUTOLOADER_PATH') ? constant('pocketmine\\COMPOSER_AUTOLOADER_PATH') : null;
        if (!is_string($autoloadPath) || !is_file($autoloadPath)) {
            self::markTestSkipped('A prepared PocketMine runtime with its own vendor/autoload.php is required for a dedicated-thread smoke test.');
        }

        $this->copyFixture('BuildPlugin', 'plugins/BuildFixture');
        $this->copyFixture('SharedVirion', 'virions/SharedVirion');

        $requests = new ThreadSafeArray();
        $responses = new ThreadSafeArray();
        $sleeper = new SleeperHandler();
        $entry = $sleeper->addNotifier(static function (): void {});
        $worker = new BuildWorkerThread($requests, $responses, $entry);
        $loader = new ThreadSafeClassLoader();
        $root = dirname(__DIR__, 2);
        $loader->addPath('NhanAZ\\DevTools', $root . '/src');
        $loader->addPath('pocketmine', $root . '/vendor/axolotl-pm/pocketmine-mp/src');
        $worker->setClassLoaders([$loader]);
        $worker->start();

        $worker->submitRequest(json_encode([
            'jobId' => 'thread-test',
            'operation' => 'build',
            'payload' => [
                'target' => 'plugin',
                'selection' => 'BuildFixture',
                'overwrite' => false,
                'pluginsDirectory' => $this->temporaryDirectory . '/plugins',
                'virionsDirectory' => $this->temporaryDirectory . '/virions',
                'buildDirectory' => $this->temporaryDirectory . '/build',
            ],
        ], JSON_THROW_ON_ERROR));

        $response = null;
        for ($attempt = 0; $attempt < 2_000; ++$attempt) {
            $candidate = $worker->takeResponse();
            if (is_string($candidate)) {
                $response = $candidate;
                break;
            }
            usleep(10_000);
        }

        $worker->requestShutdown();
        $worker->join();
        $sleeper->removeNotifier($entry->getNotifierId());

        if ($response === null) {
            throw new RuntimeException('The dedicated build worker did not return a result.');
        }
        /** @var array{success: bool, messages: list<string>} $result */
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['success'], implode("\n", $result['messages']));
        self::assertFileExists($this->temporaryDirectory . '/build/BuildFixture.phar');
    }
}
