<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

use AxolotlBucketLabs\DevTools\Build\PhpParserNamespaceLoader;
use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use Throwable;
use function count;
use function dirname;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class BuildWorkerThread extends Thread
{
	private bool $shutdown = false;

	/**
	 * @param ThreadSafeArray<int, string> $requests
	 * @param ThreadSafeArray<int, string> $responses
	 */
	public function __construct(
		private readonly ThreadSafeArray $requests,
		private readonly ThreadSafeArray $responses,
		private readonly SleeperHandlerEntry $sleeperEntry,
	) {}

	public function requestShutdown() : void
	{
		$this->synchronized(function () : void {
			$this->shutdown = true;
			$this->notify();
		});
	}

	public function submitRequest(string $request) : void
	{
		$this->synchronized(function () use ($request) : void {
			$this->requests[] = $request;
			$this->notify();
		});
	}

	public function takeResponse() : ?string
	{
		$response = $this->responses->shift();

		return is_string($response) ? $response : null;
	}

	protected function onRun() : void
	{
		PhpParserNamespaceLoader::register(dirname(__DIR__, 2) . '/vendor/nikic/php-parser/lib/PhpParser');
		$notifier = $this->sleeperEntry->createNotifier();

		while (true) {
			$request = $this->requests->shift();
			if (is_string($request)) {
				$this->runRequest($request, $notifier);
				continue;
			}

			$this->synchronized(function () : void {
				if (!$this->shutdown && count($this->requests) === 0) {
					$this->wait();
				}
			});
			if ($this->shutdown && count($this->requests) === 0) {
				return;
			}
		}
	}

	private function runRequest(string $request, \pocketmine\snooze\SleeperNotifier $notifier) : void
	{
		try {
			/** @var array{jobId: string, operation: string, payload: array<string, mixed>} $decoded */
			$decoded = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
			$result = BuildWorkerApplication::run($decoded['operation'], $decoded['payload']);
			$result['jobId'] = $decoded['jobId'];
		} catch (Throwable $error) {
			$result = [
				'jobId' => '',
				'success' => false,
				'messages' => ["DevTools worker failed.\n{$error->getMessage()}"],
			];
		}
		$this->responses[] = json_encode($result, JSON_THROW_ON_ERROR);
		$notifier->wakeupSleeper();
	}
}
