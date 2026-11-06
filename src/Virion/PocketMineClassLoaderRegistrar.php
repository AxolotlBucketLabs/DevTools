<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use pocketmine\thread\ThreadSafeClassLoader;

final class PocketMineClassLoaderRegistrar implements ClassLoaderRegistrar
{
    public function __construct(private readonly ThreadSafeClassLoader $classLoader) {}

    public function register(string $namespacePrefix, string $sourceRoot): void
    {
        $this->classLoader->addPath($namespacePrefix, $sourceRoot);
    }

    public function supportsAsyncWorkers(): bool
    {
        return true;
    }

    public function asyncSupportDescription(): string
    {
        return 'Registered on Axolotl-PM\'s shared ThreadSafeClassLoader. Updates are visible to existing and future workers.';
    }
}
