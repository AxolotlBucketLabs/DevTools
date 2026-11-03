<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Virion;

use function get_loaded_extensions;
use function implode;
use function str_replace;
use function strtolower;
use function version_compare;

final class VirionPlatformRequirements
{
    public function validateManifest(VirionManifest $manifest): void
    {
        if (isset($manifest->raw['composer-platform'])) {
            $this->validate($manifest->raw['composer-platform'], $manifest->name);
        }
        $this->validatePhpVersions($manifest->php, $manifest->name);
    }

    public function validate(mixed $requirements, string $source): void
    {
        if (!is_array($requirements)) {
            throw new VirionException("Invalid Composer platform requirements in {$source}.");
        }
        foreach ($requirements as $name => $constraint) {
            if (!is_string($name) || !is_string($constraint)) {
                throw new VirionException("Invalid Composer platform requirement in {$source}.");
            }
            if ($name === 'php') {
                if (!$this->matchesPhp($constraint)) {
                    throw new VirionException("{$source} requires PHP {$constraint}. Current PHP is " . PHP_VERSION . '.');
                }
            } elseif ($name === 'php-64bit') {
                if ($constraint !== '*' || PHP_INT_SIZE !== 8) {
                    throw new VirionException("{$source} requires 64-bit PHP. Current PHP integer size is " . PHP_INT_SIZE . ' bytes.');
                }
            } elseif (str_starts_with($name, 'ext-')) {
                if ($constraint !== '*') {
                    throw new VirionException("{$source} uses unsupported versioned extension requirement {$name} {$constraint}. Only * is supported.");
                }
                $extension = strtolower(str_replace(' ', '-', substr($name, 4)));
                $loaded = [];
                foreach (get_loaded_extensions() as $loadedExtension) {
                    $loaded[strtolower(str_replace(' ', '-', $loadedExtension))] = true;
                }
                if (!isset($loaded[$extension])) {
                    throw new VirionException("{$source} requires missing PHP extension {$name}.");
                }
            } else {
                throw new VirionException("Unsupported platform requirement {$name} in {$source}.");
            }
        }
    }

    /** @param list<string> $versions */
    public function validatePhpVersions(array $versions, string $source): void
    {
        if ($versions === []) {
            return;
        }
        foreach ($versions as $minimum) {
            if (version_compare(PHP_VERSION, $minimum, '>=')) {
                return;
            }
        }
        throw new VirionException(
            "{$source} requires one of PHP " . implode(', ', $versions) . '. Current PHP is ' . PHP_VERSION . '.',
        );
    }

    private function matchesPhp(string $expression): bool
    {
        $matched = false;
        foreach (explode('||', $expression) as $alternative) {
            $tokens = preg_split('/\s+/', trim($alternative)) ?: [];
            $normalized = [];
            foreach ($tokens as $token) {
                if ($token === '*') {
                    $normalized[] = '*';
                    continue;
                }
                if (preg_match('/^(\^|>=|<=|>|<|=)?(\d+)\.(\d+)(?:\.(\d+))?$/D', $token, $match) !== 1) {
                    throw new VirionException("Unsupported Composer PHP constraint {$expression}. Supported: caret versions and comparison ranges, optionally separated by ||.");
                }
                $normalized[] = $match[1] . $match[2] . '.' . $match[3] . '.' . ($match[4] ?? '0');
            }
            $matched = (new VirionVersionConstraint(implode(' ', $normalized)))->matches(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION) || $matched;
        }
        return $matched;
    }
}
