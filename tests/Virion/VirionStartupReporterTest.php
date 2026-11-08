<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Virion\VirionManifest;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistryEntry;
use AxolotlBucketLabs\DevTools\Virion\VirionStartupReporter;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use PHPUnit\Framework\TestCase;

final class VirionStartupReporterTest extends TestCase
{
    public function test_skipped_candidates_are_grouped_by_virion_name(): void
    {
        $registry = new VirionRegistry();
        $registry->record($this->entry('1.0.0', 'first path'));
        $registry->record($this->entry('1.1.0', 'second path'));

        $messages = (new VirionStartupReporter())->messages($registry);

        self::assertCount(1, $messages);
        self::assertSame('warning', $messages[0]['level']);
        self::assertSame(
            'Skipped SharedVirion candidates (1.0.0, 1.1.0). See /devtools virions for selection details.',
            $messages[0]['message'],
        );
        self::assertSame(['first path', 'second path'], $messages[0]['details']);
    }

    private function entry(string $version, string $message): VirionRegistryEntry
    {
        return new VirionRegistryEntry(
            $version,
            new VirionManifest('SharedVirion', $version, 'Shared\\Virion', [], [], [], $version),
            VirionStatus::SKIPPED,
            $message,
        );
    }
}
