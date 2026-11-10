<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Loader;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\SharedVirionConfigResolver;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginLoader;
use AxolotlBucketLabs\DevTools\Loader\PluginDependencyIndex;
use AxolotlBucketLabs\DevTools\Loader\PluginSourceConflictDetector;
use AxolotlBucketLabs\DevTools\Runtime\PluginDependencyCollector;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use Phar;
use pocketmine\plugin\PluginDescriptionParseException;
use pocketmine\thread\ThreadSafeClassLoader;
use function dirname;
use function file_put_contents;
use function str_replace;
use const DIRECTORY_SEPARATOR;

final class FolderPluginLoaderTest extends TestCase
{
	public function test_valid_folder_is_discovered_and_registered() : void
	{
		$plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
		$this->filesystem->ensureDirectory($plugins);
		$project = $this->copyFixture('ValidPlugin', 'plugins/ValidPlugin');
		[$reader, $validator] = $this->validationServices();
		$classLoader = new ThreadSafeClassLoader();
		$loader = new FolderPluginLoader($classLoader, $reader, $validator);

		self::assertSame([str_replace('\\', '/', $project)], (new FolderPluginDiscovery())->discover($plugins));
		self::assertTrue($loader->canLoadPlugin($project));
		self::assertSame('FixturePlugin', $loader->getPluginDescription($project)?->getName());

		$loader->loadPlugin($project);

		self::assertSame(
			str_replace('/', DIRECTORY_SEPARATOR, $project . '/src/Main.php'),
			$classLoader->findClass('Fixture\Plugin\Main'),
		);
		self::assertFileExists($project . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config.yml');
	}

	public function test_configured_devtools_source_project_is_not_loadable() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugins/DevTools');
		[$reader, $validator] = $this->validationServices();
		$loader = new FolderPluginLoader(new ThreadSafeClassLoader(), $reader, $validator, $project);

		self::assertFalse($loader->canLoadPlugin($project));
		self::assertNull($loader->getPluginDescription($project));
	}

	public function test_invalid_folder_is_not_silently_ignored() : void
	{
		$project = $this->copyFixture('MissingMain');
		[$reader, $validator] = $this->validationServices();
		$loader = new FolderPluginLoader(new ThreadSafeClassLoader(), $reader, $validator);

		self::assertTrue($loader->canLoadPlugin($project));
		$this->expectException(PluginDescriptionParseException::class);
		$this->expectExceptionMessage('src/Main.php');

		$loader->getPluginDescription($project);
	}

	public function test_folder_load_failure_uses_runtime_language_and_preserves_technical_detail() : void
	{
		$project = $this->copyFixture('MissingMain');
		[$reader, $validator] = $this->validationServices();
		$loader = new FolderPluginLoader(new ThreadSafeClassLoader(), $reader, $validator, translator: Translator::fromResources('vie'));

		$this->expectException(PluginDescriptionParseException::class);
		$this->expectExceptionMessage('Không thể nạp plugin dạng folder');
		$this->expectExceptionMessage('src/Main.php');

		$loader->getPluginDescription($project);
	}

	public function test_project_with_invalid_devtools_configuration_is_not_loadable() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugins/InvalidConfiguration');
		file_put_contents($project . '/devtools.yml', "include-paths: LICENSE\n");
		[$reader, $validator] = $this->validationServices();
		$loader = new FolderPluginLoader(
			new ThreadSafeClassLoader(),
			$reader,
			$validator,
			null,
			null,
			$this->dependencies($project),
		);

		$this->expectException(PluginDescriptionParseException::class);
		$this->expectExceptionMessage('must be a list of relative paths');

		$loader->getPluginDescription($project);
	}

	public function test_missing_required_virion_is_rejected_before_plugin_discovery() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugins/MissingVirion');
		file_put_contents($project . '/devtools.yml', "virions: [ExampleVirion]\n");
		[$reader, $validator] = $this->validationServices();
		$loader = new FolderPluginLoader(
			new ThreadSafeClassLoader(),
			$reader,
			$validator,
			null,
			null,
			$this->dependencies($project),
		);

		$this->expectException(PluginDescriptionParseException::class);
		$this->expectExceptionMessage('Required virion ExampleVirion is missing');

		$loader->getPluginDescription($project);
	}

	public function test_direct_load_rejects_missing_dependencies_before_class_registration() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/devtools.yml', "virions: [ExampleVirion]\n");
		[$reader, $validator] = $this->validationServices();
		$classLoader = new ThreadSafeClassLoader();
		$loader = new FolderPluginLoader($classLoader, $reader, $validator, dependencies: $this->dependencies($project));
		try {
			$loader->loadPlugin($project);
			self::fail('A plugin with a missing dependency must not register its source path.');
		} catch (\RuntimeException $error) {
			self::assertStringContainsString('Required virion ExampleVirion is missing', $error->getMessage());
		}
		self::assertNull($classLoader->findClass('Fixture\\Plugin\\Main'));
	}

	public function test_direct_load_rejects_configuration_errors_before_class_registration() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/devtools.yml', "include-paths: LICENSE\n");
		[$reader, $validator] = $this->validationServices();
		$classLoader = new ThreadSafeClassLoader();
		$loader = new FolderPluginLoader($classLoader, $reader, $validator, dependencies: $this->dependencies($project));
		try {
			$loader->loadPlugin($project);
			self::fail('A plugin with invalid configuration must not register its source path.');
		} catch (\RuntimeException $error) {
			self::assertStringContainsString('must be a list of relative paths', $error->getMessage());
		}
		self::assertNull($classLoader->findClass('Fixture\\Plugin\\Main'));
	}

	private function dependencies(string $project) : PluginDependencyIndex
	{
		[$reader] = $this->validationServices();
		$discovery = new VirionDiscovery($this->filesystem);
		$factory = new VirionProjectFactory(new VirionManifestReader(new YamlReader()));
		$collector = new PluginDependencyCollector(
			new FolderPluginDiscovery(),
			$reader,
			new SharedVirionConfigResolver(new BuildConfigResolver(new YamlReader()), $discovery, $factory),
			new VirionRequirementChecker(new VirionRegistry(), new VirionProjectSelector()),
			static function (string $message) : void {},
			Translator::fromResources(),
		);
		return $collector->collect(dirname($project), $this->temporaryDirectory . '/virions');
	}

	public function test_hidden_and_build_directories_are_skipped() : void
	{
		$plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
		$this->filesystem->ensureDirectory($plugins . DIRECTORY_SEPARATOR . '.hidden' . DIRECTORY_SEPARATOR . 'src');
		$this->filesystem->ensureDirectory($plugins . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'src');

		self::assertSame([], (new FolderPluginDiscovery())->discover($plugins));
	}

	public function test_source_folder_without_manifest_is_reported() : void
	{
		$project = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'IncompletePlugin';
		$this->filesystem->ensureDirectory($project . DIRECTORY_SEPARATOR . 'src');
		[$reader, $validator] = $this->validationServices();
		$loader = new FolderPluginLoader(new ThreadSafeClassLoader(), $reader, $validator);

		self::assertTrue($loader->canLoadPlugin($project));
		$this->expectException(PluginDescriptionParseException::class);
		$this->expectExceptionMessage('plugin.yml is missing');

		$loader->getPluginDescription($project);
	}

	public function test_folder_and_phar_with_the_same_plugin_name_are_reported() : void
	{
		$plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
		$this->filesystem->ensureDirectory($plugins);
		$this->copyFixture('ValidPlugin', 'plugins/ValidPlugin');
		$source = $this->copyFixture('ValidPlugin', 'source/ValidPlugin');
		$path = $plugins . DIRECTORY_SEPARATOR . 'ValidPlugin.phar';
		$phar = new Phar($path);
		$phar->buildFromDirectory($source);
		$phar->setStub("<?php __HALT_COMPILER();\n");
		unset($phar);
		[$reader] = $this->validationServices();

		$warnings = (new PluginSourceConflictDetector($reader))->detect($plugins);

		self::assertCount(1, $warnings);
		self::assertStringContainsString('development folder', $warnings[0]);
		self::assertStringContainsString('PHAR', $warnings[0]);
		$localizedWarnings = (new PluginSourceConflictDetector($reader))->detect($plugins, null, Translator::fromResources('vie'));
		self::assertStringContainsString('có cả thư mục phát triển và PHAR', $localizedWarnings[0]);
		self::assertSame([], (new PluginSourceConflictDetector($reader))->detect($plugins, $plugins . DIRECTORY_SEPARATOR . 'ValidPlugin'));
	}

	public function test_duplicate_plugin_sources_are_grouped_and_paths_are_debugged() : void
	{
		$plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
		$this->filesystem->ensureDirectory($plugins);
		$this->copyFixture('ValidPlugin', 'plugins/ValidPluginOne');
		$this->copyFixture('ValidPlugin', 'plugins/ValidPluginTwo');
		$source = $this->copyFixture('ValidPlugin', 'source/ValidPlugin');
		$path = $plugins . DIRECTORY_SEPARATOR . 'ValidPlugin.phar';
		$phar = new Phar($path);
		$phar->buildFromDirectory($source);
		$phar->setStub("<?php __HALT_COMPILER();\n");
		unset($phar);
		[$reader] = $this->validationServices();
		$debugMessages = [];

		$warnings = (new PluginSourceConflictDetector(
			$reader,
			null,
			new DebugLogger(static function (string $message) use (&$debugMessages) : void {
				$debugMessages[] = $message;
			}),
		))->detect($plugins);

		self::assertCount(1, $warnings);
		self::assertStringContainsString('FixturePlugin', $warnings[0]);
		self::assertStringContainsString('ValidPluginOne', $debugMessages[0]);
		self::assertStringContainsString('ValidPluginTwo', $debugMessages[0]);
		self::assertStringContainsString('ValidPlugin.phar', $debugMessages[0]);
	}

	public function test_plugin_cannot_shadow_a_loaded_shared_virion() : void
	{
		$project = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'CollisionPlugin';
		$this->filesystem->ensureDirectory($project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion');
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'plugin.yml',
			"name: CollisionPlugin\nversion: 1.0.0\nmain: Main\napi: 5.0.0\n",
		);
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php',
			"<?php\nfinal class Main extends \\pocketmine\\plugin\\PluginBase {}\n",
		);
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Value.inc',
			"<?php\nnamespace Shared\\Virion; final class Value {}\n",
		);
		[$reader, $validator] = $this->validationServices();
		$detector = new PluginSourceConflictDetector($reader);
		$loader = new FolderPluginLoader(
			new ThreadSafeClassLoader(),
			$reader,
			$validator,
			null,
			static function (string $root) use ($detector) : array {
				return $detector->detectVirionConflicts($root, ['Shared\\Virion'], ['Shared\\Virion\\Value']);
			},
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('shadows shared virion class');

		$loader->loadPlugin($project);
	}
}
