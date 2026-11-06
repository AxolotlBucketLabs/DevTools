<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

interface ArchiveReader
{
    /** @return iterable<ArchiveEntry> */
    public function entries(): iterable;
}
