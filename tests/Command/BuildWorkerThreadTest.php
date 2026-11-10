<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Command;

use AxolotlBucketLabs\DevTools\Command\BuildWorkerThread;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\ThreadSafeClassLoader;
use RuntimeException;
use function constant;
use function count;
use function defined;
use function dirname;
use function file_put_contents;
use function implode;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function usleep;
use const JSON_THROW_ON_ERROR;

#[RequiresPhpExtension('pmmpthread')]
final class BuildWorkerThreadTest extends TestCase
{
	public function test_worker_builds_without_using_the_server_async_pool() : void
	{
		$autoloadPath = defined('pocketmine\\COMPOSER_AUTOLOADER_PATH') ? constant('pocketmine\\COMPOSER_AUTOLOADER_PATH') : null;
		if (!is_string($autoloadPath) || !is_file($autoloadPath)) {
			self::markTestSkipped('A prepared PocketMine runtime with its own vendor/autoload.php is required for a dedicated-thread smoke test.');
		}

		$this->copyFixture('BuildPlugin', 'plugins/BuildFixture');
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$dual = $this->copyFixture('ValidPlugin', 'plugins/Dual');
		$this->filesystem->ensureDirectory($dual . '/src/Fixture/Library');
		file_put_contents(
			$dual . '/src/Fixture/Library/Library.php',
			"<?php\nnamespace Fixture\\Library;\nfinal class Library {}\n",
		);
		file_put_contents(
			$dual . '/virion.yml',
			"name: DualLibrary\nversion: 1.0.0\nantigen: Fixture\\Library\napi: 5.0.0\n",
		);

		$requests = new ThreadSafeArray();
		$responses = new ThreadSafeArray();
		$sleeper = new SleeperHandler();
		$entry = $sleeper->addNotifier(static function () : void {});
		$worker = new BuildWorkerThread($requests, $responses, $entry);
		$loader = new ThreadSafeClassLoader();
		$root = dirname(__DIR__, 2);
		$loader->addPath('AxolotlBucketLabs\\DevTools', $root . '/src');
		$loader->addPath('pocketmine', $root . '/vendor/axolotl-pm/pocketmine-mp/src');
		$worker->setClassLoaders([$loader]);
		$worker->start();

		foreach ([['thread-plugin', 'plugin', 'BuildFixture'], ['thread-dual', 'both', 'FixturePlugin']] as [$jobId, $target, $selection]) {
			$worker->submitRequest(json_encode([
				'jobId' => $jobId,
				'operation' => 'build',
				'payload' => [
					'target' => $target,
					'selection' => $selection,
					'overwrite' => false,
					'pluginsDirectory' => $this->temporaryDirectory . '/plugins',
					'virionsDirectory' => $this->temporaryDirectory . '/virions',
					'buildDirectory' => $this->temporaryDirectory . '/build',
				],
			], JSON_THROW_ON_ERROR));
		}

		$completed = [];
		for ($attempt = 0; $attempt < 2_000; ++$attempt) {
			$candidate = $worker->takeResponse();
			if (is_string($candidate)) {
				$completed[] = $candidate;
				if (count($completed) === 2) {
					break;
				}
			}
			usleep(10_000);
		}

		$worker->requestShutdown();
		$worker->join();
		$sleeper->removeNotifier($entry->getNotifierId());

		if (count($completed) !== 2) {
			throw new RuntimeException('The dedicated build worker did not return both results.');
		}
		$jobIds = [];
		foreach ($completed as $response) {
			/** @var array{jobId: string, success: bool, messages: list<string>} $result */
			$result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
			self::assertTrue($result['success'], implode("\n", $result['messages']));
			$jobIds[] = $result['jobId'];
		}
		self::assertSame(['thread-plugin', 'thread-dual'], $jobIds);
		self::assertFileExists($this->temporaryDirectory . '/build/BuildFixture.phar');
		self::assertFileExists($this->temporaryDirectory . '/build/FixturePlugin-plugin.phar');
		self::assertFileExists($this->temporaryDirectory . '/build/DualLibrary-virion.phar');
	}
}
