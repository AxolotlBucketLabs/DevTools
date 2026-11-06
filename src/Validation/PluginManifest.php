<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

use pocketmine\plugin\PluginDescription;

final class PluginManifest
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly PluginDescription $description,
        public readonly array $raw,
        public readonly string $path,
    ) {}

    public function expectedMainRelativePath(): ?string
    {
        $main = trim($this->description->getMain(), '\\');
        $prefix = trim($this->description->getSrcNamespacePrefix(), '\\');
        if ($prefix !== '') {
            if (!str_starts_with($main, $prefix . '\\')) {
                return null;
            }
            $main = substr($main, strlen($prefix) + 1);
        }

        return str_replace('\\', '/', $main) . '.php';
    }
}
