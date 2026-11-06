<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\PharSnapshot;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use Phar;

final class PharSnapshotTest extends TestCase
{
    public function test_remove_releases_an_open_snapshot_and_removes_the_file(): void
    {
        $source = $this->temporaryDirectory . '/source.phar';
        $phar = new Phar($source);
        $phar['source.txt'] = 'source';
        unset($phar);

        $snapshot = PharSnapshot::create($source);
        $opened = new Phar($snapshot);
        self::assertTrue(isset($opened['source.txt']));
        unset($opened);

        PharSnapshot::remove($snapshot);

        self::assertFileDoesNotExist($snapshot);
    }
}
