<?php

declare(strict_types=1);

namespace RuntimeSmoke\Library;

final class Value
{
    public static int $counter = 0;

    public static function version(): string
    {
        return '{{VERSION}}';
    }
}
