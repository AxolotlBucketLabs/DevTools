<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function bin2hex;
use function copy;
use function file_exists;
use function is_file;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

final class PharSnapshot
{
    public static function create(string $source): string
    {
        $snapshot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devtools-phar-' . bin2hex(random_bytes(12)) . '.phar';
        if (!copy($source, $snapshot)) {
            throw new BuildException("Cannot snapshot PHAR {$source}.");
        }

        return $snapshot;
    }

    public static function remove(string $snapshot): void
    {
        if (file_exists($snapshot) && is_file($snapshot)) {
            unlink($snapshot);
        }
    }
}
