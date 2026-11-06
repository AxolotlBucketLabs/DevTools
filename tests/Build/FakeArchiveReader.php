<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\ArchiveEntry;
use AxolotlBucketLabs\DevTools\Build\ArchiveReader;

final class FakeArchiveReader implements ArchiveReader
{
    /** @param list<ArchiveEntry> $entries */
    public function __construct(private readonly array $entries) {}

    public function entries(): iterable
    {
        yield from $this->entries;
    }
}
