<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use Closure;

final class DebugLogger
{
    /** @var Closure(string): void|null */
    private readonly ?Closure $sink;

    /** @param (Closure(string): void)|null $sink */
    public function __construct(?Closure $sink = null)
    {
        $this->sink = $sink;
    }

    public function enabled(): bool
    {
        return $this->sink !== null;
    }

    public function debug(string $message): void
    {
        if ($this->sink !== null) {
            ($this->sink)($message);
        }
    }
}
