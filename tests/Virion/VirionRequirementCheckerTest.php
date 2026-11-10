<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\Translator;
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
	public function test_missing_requirement_is_reported() : void
	{
		$checker = new VirionRequirementChecker(new VirionRegistry(), new VirionProjectSelector());

		$failure = $checker->failure([
			new VirionRequirement('ExampleVirion', '1.0.0', 'ExamplePlugin/composer.lock:example/virion'),
		]);

		self::assertNotNull($failure);
		self::assertStringContainsString('ExampleVirion is missing', $failure);
		self::assertStringContainsString('ExamplePlugin/composer.lock:example/virion', $failure);
	}

	public function test_missing_requirement_uses_runtime_language() : void
	{
		$checker = new VirionRequirementChecker(new VirionRegistry(), new VirionProjectSelector(), Translator::fromResources('vie'));

		$failure = $checker->failure([
			new VirionRequirement('ExampleVirion', '1.0.0', 'ExamplePlugin/composer.lock:example/virion'),
		]);

		self::assertNotNull($failure);
		self::assertStringStartsWith('Thiếu virion bắt buộc ExampleVirion.', $failure);
		self::assertStringContainsString('ExamplePlugin/composer.lock:example/virion', $failure);
	}

	public function test_incompatible_requirement_uses_runtime_language_and_keeps_versions() : void
	{
		$registry = new VirionRegistry();
		$registry->record(new VirionRegistryEntry(
			$this->temporaryDirectory . '/virions/ExampleVirion',
			new VirionManifest('ExampleVirion', '2.0.0', 'Example\\Virion', [], [], [], 'virion.yml'),
			VirionStatus::LOADED,
			'Loaded ExampleVirion 2.0.0.',
		));
		$checker = new VirionRequirementChecker($registry, new VirionProjectSelector(), Translator::fromResources('vie'));

		$failure = $checker->failure([
			new VirionRequirement('ExampleVirion', '^1.0.0', 'ExamplePlugin/composer.lock:example/virion'),
		]);

		self::assertNotNull($failure);
		self::assertStringStartsWith('Virion bắt buộc ExampleVirion không được nạp với phiên bản tương thích.', $failure);
		self::assertStringContainsString('Đã nạp: 2.0.0.', $failure);
		self::assertStringContainsString('^1.0.0', $failure);
	}

	public function test_loaded_compatible_requirement_passes() : void
	{
		$registry = new VirionRegistry();
		$registry->record(new VirionRegistryEntry(
			$this->temporaryDirectory . '/virions/ExampleVirion',
			new VirionManifest('ExampleVirion', '1.0.0', 'Example\\Virion', [], [], [], 'virion.yml'),
			VirionStatus::LOADED,
			'Loaded ExampleVirion 1.0.0.',
		));
		$checker = new VirionRequirementChecker($registry, new VirionProjectSelector());

		self::assertNull($checker->failure([
			new VirionRequirement('ExampleVirion', '1.0.0', 'ExamplePlugin/composer.lock:example/virion'),
		]));
	}
}
