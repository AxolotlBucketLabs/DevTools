<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Loader;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\SharedVirionConfigResolver;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Runtime\PluginDependencyCollector;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifest;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistryEntry;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use function file_put_contents;

final class PluginDependencyIndexTest extends TestCase
{
	public function test_requirements_are_project_scoped_and_observe_the_loaded_registry() : void
	{
		$dependent = $this->copyFixture('BuildPlugin', 'plugins/Dependent');
		$independent = $this->copyFixture('ValidPlugin', 'plugins/Independent');
		$registry = new VirionRegistry();
		$warnings = [];
		$index = $this->collector($registry, $warnings)->collect($this->temporaryDirectory . '/plugins', $this->temporaryDirectory . '/virions');

		self::assertSame([], $warnings);
		self::assertCount(1, $index->requirements());
		self::assertSame('SharedVirion', $index->requirements()[0]->name);
		self::assertNotNull($index->dependencyFailure($dependent . '/.'));
		self::assertNull($index->dependencyFailure($independent));

		$manifest = new VirionManifest('SharedVirion', '1.0.0', 'Shared\\Virion', [], [], [], 'virion.yml');
		$registry->record(new VirionRegistryEntry('library', $manifest, VirionStatus::LOADED, 'Loaded'));

		self::assertNull($index->dependencyFailure($dependent));
		self::assertNull($index->dependencyFailure($independent));
	}

	public function test_bad_configuration_and_manifest_do_not_block_unrelated_projects() : void
	{
		$invalid = $this->copyFixture('ValidPlugin', 'plugins/BadConfiguration');
		$broken = $this->copyFixture('ValidPlugin', 'plugins/BadManifest');
		$healthy = $this->copyFixture('ValidPlugin', 'plugins/Healthy');
		file_put_contents($invalid . '/devtools.yml', "include-paths: LICENSE\n");
		file_put_contents($broken . '/plugin.yml', "name: Broken\n");
		$warnings = [];
		$index = $this->collector(new VirionRegistry(), $warnings)->collect($this->temporaryDirectory . '/plugins', $this->temporaryDirectory . '/virions');

		self::assertCount(2, $warnings);
		self::assertNotNull($index->configurationFailure($invalid . '/.'));
		self::assertNull($index->configurationFailure($healthy));
		self::assertNull($index->dependencyFailure($healthy));
		self::assertSame([], $index->requirements());
	}

	/** @param list<string> $warnings */
	private function collector(VirionRegistry $registry, array &$warnings) : PluginDependencyCollector
	{
		[$reader] = $this->validationServices();
		return new PluginDependencyCollector(
			new FolderPluginDiscovery(),
			$reader,
			new SharedVirionConfigResolver(
				new BuildConfigResolver(new YamlReader()),
				new VirionDiscovery($this->filesystem),
				new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
			),
			new VirionRequirementChecker($registry, new VirionProjectSelector()),
			static function (string $message) use (&$warnings) : void {
				$warnings[] = $message;
			},
			Translator::fromResources(),
		);
	}
}
