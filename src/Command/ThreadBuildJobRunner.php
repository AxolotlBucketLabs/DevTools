<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Command;

use function bin2hex;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use pmmp\thread\ThreadSafeArray;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperHandlerEntry;

use function random_bytes;

final class ThreadBuildJobRunner implements BuildJobRunner
{
    /** @var array<string, callable(array{success: bool, messages: list<string>}): void> */
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
    ) {
        $this->tickSleeper = $server->getTickSleeper();
        $requests = new ThreadSafeArray();
        $responses = new ThreadSafeArray();
        $this->sleeperEntry = $this->tickSleeper->addNotifier(function (): void {
            $this->drain();
        });
        $this->worker = new BuildWorkerThread($requests, $responses, $this->sleeperEntry);
        $this->worker->start();
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(array{success: bool, messages: list<string>}): void $onComplete
     */
    public function submit(string $operation, array $payload, callable $onComplete): bool
    {
        if ($this->isBusy() || $this->worker->isTerminated()) {
            return false;
        }

        $jobId = bin2hex(random_bytes(8));
        $payload = array_merge($payload, [
            'pluginsDirectory' => $this->pluginsDirectory,
            'virionsDirectory' => $this->virionsDirectory,
            'buildDirectory' => $this->buildDirectory,
        ]);
        $this->activeJobId = $jobId;
        $this->callbacks[$jobId] = $onComplete;
        $this->worker->submitRequest(json_encode([
            'jobId' => $jobId,
            'operation' => $operation,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));

        return true;
    }

    public function isBusy(): bool
    {
        return $this->activeJobId !== null;
    }

    public function status(): string
    {
        return $this->isBusy() ? 'running' : 'idle';
    }

    public function shutdown(): void
    {
        $this->worker->requestShutdown();
        $this->worker->join();
        $this->tickSleeper->removeNotifier($this->sleeperEntry->getNotifierId());
    }

    private function drain(): void
    {
        while (($response = $this->worker->takeResponse()) !== null) {
            /** @var array{jobId: string, success: bool, messages: list<string>} $result */
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
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
                ]);
            }
        }
    }
}
