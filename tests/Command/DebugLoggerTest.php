<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Command;

use AxolotlBucketLabs\DevTools\Command\BuildWorkerApplication;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Tests\TestCase;

final class DebugLoggerTest extends TestCase
{
    public function test_logger_can_be_enabled_or_disabled(): void
    {
        $messages = [];
        $logger = new DebugLogger(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        self::assertTrue($logger->enabled());
        $logger->debug('diagnostic');
        self::assertSame(['diagnostic'], $messages);

        $disabled = new DebugLogger();
        self::assertFalse($disabled->enabled());
        $disabled->debug('ignored');
        self::assertSame(['diagnostic'], $messages);
    }

    public function test_worker_returns_debug_messages_separately_from_command_output(): void
    {
        $result = BuildWorkerApplication::run('unknown', ['debug' => true]);

        self::assertFalse($result['success']);
        self::assertStringContainsString('Unknown background operation unknown.', $result['messages'][0]);
        self::assertStringContainsString('Worker operation unknown started.', implode("\n", $result['debug']));
        self::assertStringContainsString('Worker operation unknown failed:', implode("\n", $result['debug']));
    }
}
