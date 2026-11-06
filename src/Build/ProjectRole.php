<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

enum ProjectRole: string
{
    case PLUGIN = 'plugin';
    case VIRION = 'virion';
    case DUAL = 'dual';

    public function includes(string $role): bool
    {
        return $this === self::DUAL || $this->value === $role;
    }
}
