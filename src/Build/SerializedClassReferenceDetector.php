<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function ltrim;
use function preg_match_all;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;

final class SerializedClassReferenceDetector
{
    public static function contains(string $value, string $namespace): bool
    {
        $namespace = strtolower(ltrim($namespace, '\\'));
        foreach ([
            ['/(?:O|C):(\d+):"([^"]+)":/i', false],
            ['/E:(\d+):"([^"]+)";/i', true],
        ] as [$pattern, $enum]) {
            $matches = [];
            if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                $encodedName = str_replace('\\\\', '\\', $match[2]);
                if ((int) $match[1] !== strlen($encodedName)) {
                    continue;
                }
                $class = $enum ? substr($encodedName, 0, strpos($encodedName, ':') ?: strlen($encodedName)) : $encodedName;
                $class = strtolower(ltrim($class, '\\'));
                if ($class === $namespace || str_starts_with($class, $namespace . '\\')) {
                    return true;
                }
            }
        }

        return false;
    }
}
