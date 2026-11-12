<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\JsonLimits;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;
use pmmp\thread\ThreadSafeArray;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperHandlerEntry;
use function array_merge;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class ThreadBuildJobRunner implements BuildJobRunner
{
	/** @var array<string, callable(array{success: bool, messages: list<string>, debug?: list<string>}): void> */
	private array $callbacks = [];
	private ?string $activeJobId = null;
	private readonly SleeperHandler $tickSleeper;
	private readonly SleeperHandlerEntry $sleeperEntry;
	private readonly BuildWorkerThread $worker;

	public function __construct(
		Server $server,
		private readonly string $pluginsDirectory,
		private readonly string $virionsDirectory,
		private readonly string $buildDirectory,
		private readonly ?DebugLogger $debug = null,
		private readonly string $locale = Translator::DEFAULT_LOCALE,
	) {
		if ($this->debug?->enabled()) {
			$this->debug->setTranslator(Translator::fromResources($this->locale));
		}
		$this->tickSleeper = $server->getTickSleeper();
		$requests = new ThreadSafeArray();
		$responses = new ThreadSafeArray();
		$this->sleeperEntry = $this->tickSleeper->addNotifier(function () : void {
			$this->drain();
		});
		$this->worker = new BuildWorkerThread($requests, $responses, $this->sleeperEntry);
		$this->worker->start();
	}

	/**
	 * @param array<string, mixed>                                                               $payload
	 * @param callable(array{success: bool, messages: list<string>, debug?: list<string>}): void $onComplete
	 */
	public function submit(string $operation, array $payload, callable $onComplete) : bool
	{
		if ($this->isBusy() || $this->worker->isTerminated()) {
			$this->debug?->debugTranslated(TranslationKeys::DEBUG_BACKGROUND_JOB_REJECTED, ['operation' => $operation]);

			return false;
		}

		$jobId = RandomToken::pathSuffix();
		$payload = array_merge($payload, [
			'pluginsDirectory' => $this->pluginsDirectory,
			'virionsDirectory' => $this->virionsDirectory,
			'buildDirectory' => $this->buildDirectory,
			'locale' => $this->locale,
		]);
		$this->activeJobId = $jobId;
		$this->callbacks[$jobId] = $onComplete;
		$payload['debug'] = $this->debug?->enabled() ?? false;
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_BACKGROUND_JOB_QUEUED, ['operation' => $operation, 'job_id' => $jobId]);
		$this->worker->submitRequest(json_encode([
			'jobId' => $jobId,
			'operation' => $operation,
			'payload' => $payload,
		], JSON_THROW_ON_ERROR));

		return true;
	}

	public function isBusy() : bool
	{
		return $this->activeJobId !== null;
	}

	public function status() : string
	{
		return $this->isBusy() ? 'running' : 'idle';
	}

	public function shutdown() : void
	{
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_BUILD_WORKER_SHUTDOWN);
		$this->worker->requestShutdown();
		$this->worker->join();
		$this->tickSleeper->removeNotifier($this->sleeperEntry->getNotifierId());
	}

	private function drain() : void
	{
		while (($response = $this->worker->takeResponse()) !== null) {
			/** @var array{jobId: string, success: bool, messages: list<string>, debug?: list<string>} $result */
			$result = json_decode($response, true, JsonLimits::MAX_NESTING_DEPTH, JSON_THROW_ON_ERROR);
			$jobId = $result['jobId'];
			$callback = $this->callbacks[$jobId] ?? null;
			unset($this->callbacks[$jobId]);
			if ($this->activeJobId === $jobId) {
				$this->activeJobId = null;
			}
			if ($callback !== null) {
				$callback([
					'success' => $result['success'],
					'messages' => $result['messages'],
					'debug' => $result['debug'] ?? [],
				]);
			}
			$this->debug?->debugTranslated(TranslationKeys::DEBUG_BACKGROUND_JOB_COMPLETED, ['job_id' => $jobId]);
		}
	}
}
