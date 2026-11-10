<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\ArtifactSetInstaller;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\DualArtifactBuilder;
use AxolotlBucketLabs\DevTools\Build\ProjectRoleDetector;
use AxolotlBucketLabs\DevTools\Build\VirionBuilder;
use AxolotlBucketLabs\DevTools\Support\LocalizedException;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use Phar;
use function file_put_contents;
use function glob;
use function hash_file;
use function yaml_parse;
use const DIRECTORY_SEPARATOR;

final class DualArtifactBuilderTest extends TestCase
{
	public function test_preparation_rejects_single_role_projects_without_creating_output() : void
	{
		$builder = $this->dualBuilder();
		foreach (['ValidPlugin', 'SharedVirion'] as $fixture) {
			$project = $this->copyFixture($fixture);
			try {
				$builder->prepare($project);
				self::fail('A single-role project must not be prepared for a dual build.');
			} catch (LocalizedException $error) {
				self::assertSame(
					Translator::fromResources()->translate('build.both_required', ['project' => $project]),
					$error->render(Translator::fromResources()),
				);
			}
			self::assertDirectoryDoesNotExist($project . '/build');
		}
	}

	public function test_preparation_validates_the_virion_manifest_before_output() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/virion.yml', "name: InvalidLibrary\nversion: 1.0.0\napi: 5.0.0\n");

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('antigen');
		$this->dualBuilder()->prepare($project);
	}

	public function test_build_owns_artifact_names_and_refuses_to_replace_the_pair() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'dual');
		$this->filesystem->ensureDirectory($project . '/src/Fixture/Library');
		file_put_contents($project . '/src/Fixture/Library/Library.php', "<?php\nnamespace Fixture\\Library; final class Library {}\n");
		file_put_contents($project . '/virion.yml', "name: DualLibrary\nversion: 1.0.0\nantigen: Fixture\\Library\napi: 5.0.0\n");
		$output = $this->temporaryDirectory . '/build';
		$builder = $this->dualBuilder();
		$prepared = $builder->prepare($project);
		self::assertSame($project, $prepared->root);
		self::assertSame('FixturePlugin', $prepared->pluginName);
		self::assertSame('DualLibrary', $prepared->virionName);
		self::assertDirectoryDoesNotExist($output);

		$artifacts = $builder->build($prepared, $this->temporaryDirectory . '/virions', $output, false);

		self::assertSame($output . DIRECTORY_SEPARATOR . 'FixturePlugin-plugin.phar', $artifacts['plugin']);
		self::assertSame($output . DIRECTORY_SEPARATOR . 'DualLibrary-virion.phar', $artifacts['virion']);
		$plugin = new Phar($artifacts['plugin']);
		$virion = new Phar($artifacts['virion']);
		$pluginManifest = yaml_parse($plugin['plugin.yml']->getContent());
		$virionManifest = yaml_parse($virion['virion.yml']->getContent());
		self::assertIsArray($pluginManifest);
		self::assertIsArray($virionManifest);
		self::assertSame('FixturePlugin', $pluginManifest['name']);
		self::assertSame('DualLibrary', $virionManifest['name']);
		unset($plugin, $virion);
		$pluginHash = hash_file('sha256', $artifacts['plugin']);
		$virionHash = hash_file('sha256', $artifacts['virion']);
		try {
			$builder->build($prepared, $this->temporaryDirectory . '/virions', $output, false);
			self::fail('Existing artifacts must require explicit overwrite.');
		} catch (BuildException $error) {
			self::assertStringContainsString('Refusing to replace', $error->getMessage());
		}
		self::assertSame($pluginHash, hash_file('sha256', $artifacts['plugin']));
		self::assertSame($virionHash, hash_file('sha256', $artifacts['virion']));
		self::assertSame([], glob($output . '/.devtools-dual-*'));
	}

	private function dualBuilder() : DualArtifactBuilder
	{
		[$reader] = $this->validationServices();
		$virionReader = new VirionManifestReader(new YamlReader());

		return new DualArtifactBuilder(
			$this->filesystem,
			$this->builder(),
			new VirionBuilder($this->filesystem, $virionReader, new VirionProjectFactory($virionReader), new VirionClassScanner(new PhpSourceInspector())),
			new ArtifactSetInstaller(),
			$reader,
			$virionReader,
			new ProjectRoleDetector(),
		);
	}
}
