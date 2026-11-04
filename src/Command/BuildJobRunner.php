<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Command;

interface BuildJobRunner
{
    /**
     * @param array<string, mixed> $payload
     * @param callable(array{success: bool, messages: list<string>}): void $onComplete
     */
    public function submit(string $operation, array $payload, callable $onComplete): bool;

    public function isBusy(): bool;

    public function status(): string;

    public function shutdown(): void;
}
