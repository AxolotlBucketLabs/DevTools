<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

enum VirionStatus: string
{
    case LOADED = 'loaded';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';
    case CONFLICT = 'conflict';
}
