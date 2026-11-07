<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionManifest;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistryEntry;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;

final class VirionRequirementCheckerTest extends TestCase
{
    public function test_missing_requirement_is_reported(): void
    {
        $checker = new VirionRequirementChecker(new VirionRegistry(), new VirionProjectSelector());

        $failure = $checker->failure([
            new VirionRequirement('dktapps.pmforms', '2.3.1', 'NPC/composer.lock:dktapps/pmforms'),
        ]);

        self::assertNotNull($failure);
        self::assertStringContainsString('dktapps.pmforms is missing', $failure);
        self::assertStringContainsString('NPC/composer.lock:dktapps/pmforms', $failure);
    }

    public function test_loaded_compatible_requirement_passes(): void
    {
        $registry = new VirionRegistry();
        $registry->record(new VirionRegistryEntry(
            $this->temporaryDirectory . '/virions/dktapps.pmforms',
            new VirionManifest('dktapps.pmforms', '2.3.1', 'dktapps\\pmforms', [], [], [], 'virion.yml'),
            VirionStatus::LOADED,
            'Loaded dktapps.pmforms 2.3.1.',
        ));
        $checker = new VirionRequirementChecker($registry, new VirionProjectSelector());

        self::assertNull($checker->failure([
            new VirionRequirement('dktapps.pmforms', '2.3.1', 'NPC/composer.lock:dktapps/pmforms'),
        ]));
    }
}
