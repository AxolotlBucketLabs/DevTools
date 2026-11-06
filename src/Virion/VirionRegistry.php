<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use function strtolower;

final class VirionRegistry
{
    /** @var list<VirionRegistryEntry> */
    private array $entries = [];

    /** @var array<string, VirionRegistryEntry> */
    private array $loadedByName = [];

    /** @var array<string, VirionRegistryEntry> */
    private array $loadedByAntigen = [];

    /** @var array<string, VirionRegistryEntry> */
    private array $loadedByClass = [];

    /** @var array<string, VirionRegistryEntry> */
    private array $loadedByFunction = [];

    /** @var array<string, VirionRegistryEntry> */
    private array $loadedByConstant = [];

    public function record(VirionRegistryEntry $entry): void
    {
        $this->entries[] = $entry;
        if ($entry->status !== VirionStatus::LOADED || $entry->manifest === null) {
            return;
        }
        $this->loadedByName[VirionName::key($entry->manifest->name)] = $entry;
        $this->loadedByAntigen[strtolower($entry->manifest->antigen)] = $entry;
        foreach ($entry->classes as $class) {
            $this->loadedByClass[strtolower($class)] = $entry;
        }
        foreach ($entry->functions as $function) {
            $this->loadedByFunction[strtolower($function)] = $entry;
        }
        foreach ($entry->constants as $constant) {
            $this->loadedByConstant[$constant] = $entry;
        }
    }

    /** @return list<VirionRegistryEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<VirionRegistryEntry> */
    public function loaded(): array
    {
        return array_values($this->loadedByName);
    }

    public function findByName(string $name): ?VirionRegistryEntry
    {
        return $this->loadedByName[VirionName::key($name)] ?? null;
    }

    public function findByAntigen(string $antigen): ?VirionRegistryEntry
    {
        return $this->loadedByAntigen[strtolower($antigen)] ?? null;
    }

    public function findByClass(string $class): ?VirionRegistryEntry
    {
        return $this->loadedByClass[strtolower($class)] ?? null;
    }

    public function findByFunction(string $function): ?VirionRegistryEntry
    {
        return $this->loadedByFunction[strtolower($function)] ?? null;
    }

    public function findByConstant(string $constant): ?VirionRegistryEntry
    {
        return $this->loadedByConstant[$constant] ?? null;
    }
}
