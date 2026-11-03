<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

use function strtolower;

final class VirionName
{
    public static function key(string $name): string
    {
        return 'virion:' . strtolower($name);
    }
}
