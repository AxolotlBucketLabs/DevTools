<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\ComposerVirionPlan;
use AxolotlBucketLabs\DevTools\Build\ComposerVirionPreparer;
use AxolotlBucketLabs\DevTools\Build\VirionBuilder;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use Phar;
use function array_flip;
use function array_intersect_key;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash_file;
use function json_encode;
use function ksort;
use function md5;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function str_repeat;
use function str_replace;
use function strtolower;
use function symlink;
use function unlink;
use const FILE_APPEND;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class ComposerVirionPreparerTest extends TestCase
{
	public function test_locked_transitive_packages_build_without_redeclaring_dependencies() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		$metadata = (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		self::assertCount(2, $metadata);
		self::assertFileExists($destination . '/example.a/LICENSE');
		self::assertFileDoesNotExist($destination . '/example.dev/virion.yml');
		$result = $this->builder()->build($project, $destination, $project . '/build');
		self::assertCount(2, $result->shadedVirions);
		self::assertCount(2, $result->dependencies);
		$phar = new Phar($result->outputPath);
		$a = $result->shadedVirions['Example\\LibraryA'];
		$b = $result->shadedVirions['Example\\LibraryB'];
		$relativeA = 'src/' . str_replace('Fixture/Build/', '', str_replace('\\', '/', $a)) . '/Greeting.php';
		$relativeB = 'src/' . str_replace('Fixture/Build/', '', str_replace('\\', '/', $b)) . '/Greeting.php';
		self::assertTrue(isset($phar['META-INF/virions/example.a/devtools-provenance.json']));
		self::assertStringContainsString($b, $phar[$relativeA]->getContent());
		require_once 'phar://' . str_replace('\\', '/', $result->outputPath) . '/' . $relativeB;
		require_once 'phar://' . str_replace('\\', '/', $result->outputPath) . '/' . $relativeA;
		self::assertSame('composed resource', ($a . '\\Greeting')::message());
	}

	public function test_composer_policy_is_part_of_the_lock_content_hash() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$composer = $plan->readJson($project . '/composer.json');
		$composer['config'] = ['policy' => ['update-with-minimal-changes' => true]];
		$this->json($project . '/composer.json', $composer);

		$content = array_intersect_key($composer, array_flip([
			'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
			'minimum-stability', 'prefer-stable', 'repositories', 'extra',
		]));
		$content['config'] = ['policy' => $composer['config']['policy']];
		ksort($content);
		$lock = $plan->readJson($project . '/composer.lock');
		$lock['content-hash'] = md5(json_encode($content, JSON_THROW_ON_ERROR));
		$this->json($project . '/composer.lock', $lock);

		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared'));
	}

	public function test_empty_and_missing_source_require_metadata_are_equivalent() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'];
		self::assertIsArray($packages);
		$package = $plan->object($packages[1] ?? null, 'fixture package');
		$package['require'] = [];
		$packages[1] = $package;
		$lock['packages'] = $packages;
		$this->json($project . '/composer.lock', $lock);

		$installed = $plan->readJson($project . '/vendor/composer/installed.json');
		$installedPackages = $installed['packages'];
		self::assertIsArray($installedPackages);
		$installedPackages[1] = $package;
		$installed['packages'] = $installedPackages;
		$this->json($project . '/vendor/composer/installed.json', $installed);

		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared'));
	}

	public function test_prepared_virion_phars_preserve_composer_provenance() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);

		$yaml = new YamlReader();
		$manifestReader = new VirionManifestReader($yaml);
		$virionBuilder = new VirionBuilder(
			$this->filesystem,
			$manifestReader,
			new VirionProjectFactory($manifestReader),
			new VirionClassScanner(new PhpSourceInspector()),
		);
		$pharDirectory = $this->temporaryDirectory . '/virion-phars';
		foreach (['example.a', 'example.b'] as $name) {
			$virionBuilder->build($destination . '/' . $name, $pharDirectory);
		}

		foreach (['example.a', 'example.b'] as $name) {
			$phar = new Phar($pharDirectory . '/' . $name . '.phar');
			self::assertTrue(isset($phar['devtools-provenance.json']), $name);
		}
		self::assertCount(2, $this->builder()->build($project, $pharDirectory, $project . '/build')->dependencies);
	}

	public function test_axolotl_server_replacement_satisfies_transitive_pocketmine_requirement() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'] ?? null;
		self::assertIsArray($packages);
		$package = $plan->object($packages[0] ?? null, 'fixture package');
		$require = $plan->object($package['require'] ?? null, 'fixture requirements');
		$require['pocketmine/pocketmine-mp'] = '^5.0';
		$require['composer-runtime-api'] = '^2.0';
		$require['php-64bit'] = '*';
		$package['require'] = $require;
		$packages[0] = $package;
		$packages[] = [
			'name' => 'axolotl-pm/pocketmine-mp',
			'version' => '5.49.1',
			'replace' => ['pocketmine/pocketmine-mp' => '*'],
		];
		$lock['packages'] = $packages;
		$this->json($project . '/composer.lock', $lock);
		$installed = $plan->readJson($project . '/vendor/composer/installed.json');
		$installedPackages = $installed['packages'] ?? null;
		self::assertIsArray($installedPackages);
		$installedPackages[0] = $package;
		$installed['packages'] = $installedPackages;
		$this->json($project . '/vendor/composer/installed.json', $installed);
		$this->json($project . '/vendor/example/a/composer.json', $package);

		$prepared = (new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
		self::assertCount(2, $prepared);
		$manifest = file_get_contents($this->temporaryDirectory . '/prepared/example.a/virion.yml');
		self::assertIsString($manifest);
		self::assertStringContainsString('php-64bit', $manifest);
		self::assertStringNotContainsString('composer-runtime-api', $manifest);
		self::assertCount(2, $this->builder()->build($project, $this->temporaryDirectory . '/prepared', $project . '/build')->dependencies);
	}

	public function test_co_located_interface_gets_a_recorded_autoload_shim() : void
	{
		$project = $this->project();
		file_put_contents(
			$project . '/vendor/example/a/src/Helper.php',
			'<?php namespace Example\\LibraryA; interface HelperInterface {} final class Helper implements HelperInterface {}',
		);
		$destination = $this->temporaryDirectory . '/prepared';
		$metadata = (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		$shim = $destination . '/example.a/src/HelperInterface.php';
		self::assertSame(['HelperInterface.php' => 'Helper.php'], $metadata[0]['autoloadShims']);
		self::assertStringContainsString("require_once __DIR__ . '/' . 'Helper.php';", (string) file_get_contents($shim));
		$result = $this->builder()->build($project, $destination, $project . '/build');
		$phar = new Phar($result->outputPath);
		$shaded = str_replace('Fixture/Build/', '', str_replace('\\', '/', $result->shadedVirions['Example\\LibraryA']));
		self::assertTrue(isset($phar['src/' . $shaded . '/HelperInterface.php']));
		self::assertTrue(isset($phar['src/' . $shaded . '/Helper.php']));
	}

	public function test_misnamed_standalone_class_is_not_auto_shimmed() : void
	{
		$project = $this->project();
		file_put_contents($project . '/vendor/example/a/src/Wrong.php', '<?php namespace Example\\LibraryA; final class Other {}');
		$this->expectExceptionMessage('expected source file');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_duplicate_class_with_different_case_is_rejected() : void
	{
		$project = $this->project();
		file_put_contents($project . '/vendor/example/a/src/Other.php', '<?php namespace Example\\LibraryA; final class greeting {}');
		try {
			(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
			self::fail('Expected duplicate class rejection.');
		} catch (BuildException $error) {
			$message = $error->getMessage();
			self::assertStringContainsString('example\\librarya\\greeting', strtolower($message));
			self::assertStringContainsString('Greeting.php', $message);
			self::assertStringContainsString('Other.php', $message);
		}
	}

	public function test_transitive_pocketmine_requirement_without_axolotl_replacement_fails() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'] ?? null;
		self::assertIsArray($packages);
		$package = $plan->object($packages[0] ?? null, 'fixture package');
		$require = $plan->object($package['require'] ?? null, 'fixture requirements');
		$require['pocketmine/pocketmine-mp'] = '^5.0';
		$package['require'] = $require;
		$packages[0] = $package;
		$lock['packages'] = $packages;
		$this->json($project . '/composer.lock', $lock);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('Runtime dependency pocketmine/pocketmine-mp is absent');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_prepare_failure_preserves_existing_directory_and_cleans_staging() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		$preparer = new ComposerVirionPreparer($this->filesystem);
		$preparer->prepare($project, $destination);
		$before = hash_file('sha256', $destination . '/example.a/virion.yml');
		try {
			$preparer->prepare($project, $destination);
			self::fail('Expected output protection.');
		} catch (BuildException $error) {
			self::assertStringContainsString('Refusing to replace', $error->getMessage());
		}
		self::assertSame($before, hash_file('sha256', $destination . '/example.a/virion.yml'));
		self::assertSame([], glob($this->temporaryDirectory . '/.devtools-prepare-*'));
	}

	public function test_symlink_output_ancestor_is_rejected_before_creating_directories() : void
	{
		$project = $this->project();
		$outside = $this->temporaryDirectory . '/outside';
		$linked = $this->temporaryDirectory . '/linked';
		$this->filesystem->ensureDirectory($outside);
		set_error_handler(static fn() : bool => true);
		try {
			$created = symlink($outside, $linked);
		} finally {
			restore_error_handler();
		}
		if (!$created) {
			self::markTestSkipped('Creating directory symlinks is not permitted on this system.');
		}
		try {
			(new ComposerVirionPreparer($this->filesystem))->prepare($project, $linked . '/new-directory/prepared');
			self::fail('Expected symbolic-link destination rejection.');
		} catch (BuildException $error) {
			self::assertStringContainsString('through symbolic link', $error->getMessage());
		}
		self::assertDirectoryDoesNotExist($outside . '/new-directory');
	}

	public function test_source_directory_output_is_rejected_without_creating_directories() : void
	{
		$project = $this->project();
		$createdDirectory = $project . '/src/prepared-virions';

		try {
			(new ComposerVirionPreparer($this->filesystem))->prepare($project, $createdDirectory . '/output');
			self::fail('Expected source-directory output rejection.');
		} catch (BuildException $error) {
			self::assertStringContainsString('must be outside vendor/ and src/', $error->getMessage());
		}

		self::assertDirectoryDoesNotExist($createdDirectory);
	}

	public function test_changed_installed_metadata_is_rejected_without_output() : void
	{
		$project = $this->project();
		file_put_contents($project . '/vendor/example/a/composer.json', '{}');
		try {
			(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
			self::fail('Expected changed installed metadata failure.');
		} catch (BuildException $error) {
			self::assertStringContainsString('differs from composer.lock', $error->getMessage());
		}
		self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/prepared');
		self::assertSame([], glob($this->temporaryDirectory . '/.devtools-prepare-*'));
	}

	public function test_stale_lock_is_rejected_even_with_include_paths() : void
	{
		$project = $this->project();
		file_put_contents($project . '/composer.json', '{"require":{"example/a":"^2.0"}}');
		file_put_contents($project . '/devtools.yml', "include-paths: [LICENSE]\n");
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('composer.lock is stale');
		$this->builder()->build($project, $this->temporaryDirectory . '/prepared', $project . '/build');
	}

	public function test_changed_lock_revision_requires_new_preparation() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		$contents = file_get_contents($project . '/composer.lock');
		self::assertIsString($contents);
		file_put_contents($project . '/composer.lock', str_replace(str_repeat('a', 40), str_repeat('b', 40), $contents));
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('does not match this composer.lock');
		$this->builder()->build($project, $destination, $project . '/build');
	}

	public function test_git_development_branch_with_locked_commit_is_prepared_and_built() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$root = $plan->readJson($project . '/composer.json');
		$requirements = $root['require'] ?? null;
		self::assertIsArray($requirements);
		$requirements['example/a'] = 'dev-master#' . str_repeat('a', 40);
		$root['require'] = $requirements;
		$this->json($project . '/composer.json', $root);

		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'];
		self::assertIsArray($packages);
		$package = $plan->object($packages[0], 'fixture package');
		$package['version'] = 'dev-master';
		$package['dist'] = ['type' => 'zip', 'reference' => str_repeat('a', 40)];
		$packages[0] = $package;
		$lock['packages'] = $packages;
		ksort($root);
		$lock['content-hash'] = md5(json_encode($root, JSON_THROW_ON_ERROR));
		$this->json($project . '/composer.lock', $lock);
		$installed = $plan->readJson($project . '/vendor/composer/installed.json');
		$installedPackages = $installed['packages'] ?? null;
		self::assertIsArray($installedPackages);
		$installedPackages[0] = $package;
		$installed['packages'] = $installedPackages;
		$this->json($project . '/vendor/composer/installed.json', $installed);

		$destination = $this->temporaryDirectory . '/prepared';
		$metadata = (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		self::assertCount(2, $metadata);
		$version = '0.0.0-commit.' . str_repeat('a', 40);
		self::assertSame($version, $metadata[0]['version']);
		self::assertSame('dev-master', $metadata[0]['composerVersion']);
		self::assertStringContainsString($version, (string) file_get_contents($destination . '/example.a/virion.yml'));
		$result = $this->builder()->build($project, $destination, $project . '/build');
		self::assertCount(2, $result->dependencies);
		self::assertSame($version, $result->dependencies[0]['version']);
	}

	public function test_development_branch_without_full_git_reference_is_rejected() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'];
		self::assertIsArray($packages);
		$package = $plan->object($packages[0], 'fixture package');
		$package['version'] = 'dev-master';
		$source = $package['source'] ?? null;
		self::assertIsArray($source);
		$source['reference'] = 'abcdef0';
		$package['source'] = $source;
		$packages[0] = $package;
		$lock['packages'] = $packages;
		$this->json($project . '/composer.lock', $lock);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('locked to a full Git commit');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_development_branch_with_conflicting_dist_reference_is_rejected() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'];
		self::assertIsArray($packages);
		$package = $plan->object($packages[0], 'fixture package');
		$package['version'] = 'dev-master';
		$package['dist'] = ['type' => 'zip', 'reference' => str_repeat('b', 40)];
		$packages[0] = $package;
		$lock['packages'] = $packages;
		$this->json($project . '/composer.lock', $lock);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('conflicting source and dist commit references');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_locked_composer_build_can_explicitly_skip_legacy_poggit_libraries() : void
	{
		$project = $this->project();
		file_put_contents($project . '/.poggit.yml', "projects:\n  BuildFixture:\n    libs:\n      - src: Legacy/Package/Legacy\n        version: 3.1.0\n");
		file_put_contents($project . '/devtools.yml', "import-poggit-libraries: false\n");
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);

		self::assertCount(2, $this->builder()->build($project, $destination, $project . '/build')->dependencies);
	}

	public function test_legacy_poggit_libraries_remain_required_by_default() : void
	{
		$project = $this->project();
		file_put_contents($project . '/.poggit.yml', "projects:\n  BuildFixture:\n    libs:\n      - src: Legacy/Package/Legacy\n        version: 3.1.0\n");
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);

		$this->expectExceptionMessage('Plugin declares virion Legacy 3.1.0');
		$this->builder()->build($project, $destination, $project . '/build');
	}

	public function test_poggit_import_setting_requires_a_boolean() : void
	{
		$project = $this->project();
		file_put_contents($project . '/devtools.yml', "import-poggit-libraries: disabled\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('must be true or false');
		$this->builder()->build($project, $this->temporaryDirectory . '/prepared', $project . '/build');
	}

	public function test_unsupported_autoload_is_rejected() : void
	{
		$project = $this->project(['files' => ['bootstrap.php']]);
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('files and multiple roots');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_single_psr4_root_in_composer_array_is_prepared_and_built() : void
	{
		$project = $this->project(['psr-4' => ['Example\\LibraryA\\' => ['src/']]]);
		$destination = $this->temporaryDirectory . '/prepared';
		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination));
		self::assertCount(2, $this->builder()->build($project, $destination, $project . '/build')->dependencies);
	}

	public function test_composer_package_can_exclude_consumer_owned_root_resources() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'];
		self::assertIsArray($packages);
		$package = $plan->object($packages[0] ?? null, 'fixture package');
		$extra = $plan->object($package['extra'] ?? null, 'fixture package extra');
		$virion = $plan->object($extra['virion'] ?? null, 'fixture package virion metadata');
		$virion['exclude-root-resources'] = true;
		$extra['virion'] = $virion;
		$package['extra'] = $extra;
		$packages[0] = $package;
		$lock['packages'] = $packages;
		$this->json($project . '/composer.lock', $lock);

		$installed = $plan->readJson($project . '/vendor/composer/installed.json');
		$installedPackages = $installed['packages'];
		self::assertIsArray($installedPackages);
		$installedPackages[0] = $package;
		$installed['packages'] = $installedPackages;
		$this->json($project . '/vendor/composer/installed.json', $installed);
		$this->json($project . '/vendor/example/a/composer.json', $package);
		$this->filesystem->ensureDirectory($project . '/vendor/example/a/resources');
		file_put_contents($project . '/vendor/example/a/resources/consumer-owned.sql', 'CREATE TABLE example;');

		$destination = $this->temporaryDirectory . '/prepared';
		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination));
		self::assertFileDoesNotExist($destination . '/example.a/resources/consumer-owned.sql');
		self::assertCount(2, $this->builder()->build($project, $destination, $project . '/build')->dependencies);
	}

	public function test_composer_root_resource_exclusion_requires_a_boolean() : void
	{
		$project = $this->project();
		$plan = new ComposerVirionPlan();
		$lock = $plan->readJson($project . '/composer.lock');
		$packages = $lock['packages'];
		self::assertIsArray($packages);
		$package = $plan->object($packages[0] ?? null, 'fixture package');
		$extra = $plan->object($package['extra'] ?? null, 'fixture package extra');
		$virion = $plan->object($extra['virion'] ?? null, 'fixture package virion metadata');
		$virion['exclude-root-resources'] = 'true';
		$extra['virion'] = $virion;
		$package['extra'] = $extra;
		$packages[0] = $package;
		$lock['packages'] = $packages;
		$this->json($project . '/composer.lock', $lock);

		$installed = $plan->readJson($project . '/vendor/composer/installed.json');
		$installedPackages = $installed['packages'];
		self::assertIsArray($installedPackages);
		$installedPackages[0] = $package;
		$installed['packages'] = $installedPackages;
		$this->json($project . '/vendor/composer/installed.json', $installed);
		$this->json($project . '/vendor/example/a/composer.json', $package);
		$this->filesystem->ensureDirectory($project . '/vendor/example/a/resources');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('exclude-root-resources must be true or false');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_psr4_package_root_is_prepared_and_built() : void
	{
		$project = $this->project(['psr-4' => ['Example\\LibraryA\\' => '']]);
		self::assertTrue(rename($project . '/vendor/example/a/src/Greeting.php', $project . '/vendor/example/a/Greeting.php'));
		$destination = $this->temporaryDirectory . '/prepared';
		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination));
		self::assertFileExists($destination . '/example.a/src/Greeting.php');
		self::assertCount(2, $this->builder()->build($project, $destination, $project . '/build')->dependencies);
	}

	public function test_psr0_package_root_is_prepared_and_built() : void
	{
		$project = $this->project(['psr-0' => ['Example\\LibraryA\\' => '']]);
		$source = $project . '/vendor/example/a/Example/LibraryA/Greeting.php';
		$this->filesystem->ensureDirectory(dirname($source));
		self::assertTrue(rename($project . '/vendor/example/a/src/Greeting.php', $source));
		$destination = $this->temporaryDirectory . '/prepared';
		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination));
		self::assertFileExists($destination . '/example.a/src/Greeting.php');
		self::assertCount(2, $this->builder()->build($project, $destination, $project . '/build')->dependencies);
	}

	public function test_single_classmap_root_with_namespace_tree_is_prepared_and_built() : void
	{
		$project = $this->project(['classmap' => ['src/']]);
		$target = $project . '/vendor/example/a/src/Example/LibraryA/Greeting.php';
		$this->filesystem->ensureDirectory(dirname($target));
		self::assertTrue(rename($project . '/vendor/example/a/src/Greeting.php', $target));
		$destination = $this->temporaryDirectory . '/prepared';
		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination));
		self::assertCount(2, $this->builder()->build($project, $destination, $project . '/build')->dependencies);
	}

	public function test_classmap_package_root_is_prepared_and_built() : void
	{
		$project = $this->project(['classmap' => ['./']]);
		$source = $project . '/vendor/example/a/Example/LibraryA/Greeting.php';
		$this->filesystem->ensureDirectory(dirname($source));
		self::assertTrue(rename($project . '/vendor/example/a/src/Greeting.php', $source));
		$destination = $this->temporaryDirectory . '/prepared';
		self::assertCount(2, (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination));
		self::assertFileExists($destination . '/example.a/src/Greeting.php');
		self::assertCount(2, $this->builder()->build($project, $destination, $project . '/build')->dependencies);
	}

	public function test_classmap_source_outside_namespace_tree_is_rejected() : void
	{
		$project = $this->project(['classmap' => ['src/']]);
		$target = $project . '/vendor/example/a/src/Example/LibraryA/Greeting.php';
		$this->filesystem->ensureDirectory(dirname($target));
		self::assertTrue(rename($project . '/vendor/example/a/src/Greeting.php', $target));
		file_put_contents($project . '/vendor/example/a/src/Extra.php', '<?php final class Extra {}');
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('classmap PHP source is outside');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_prepared_source_changes_are_rejected() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		file_put_contents($destination . '/example.b/src/message.txt', 'changed source');
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('changed after preparation');
		$this->builder()->build($project, $destination, $project . '/build');
	}

	public function test_prepared_manifest_changes_are_rejected() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		file_put_contents($destination . '/example.a/virion.yml', "comment: changed\n", FILE_APPEND);
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('Prepared manifest');
		$this->builder()->build($project, $destination, $project . '/build');
	}

	public function test_repeated_local_declaration_preserves_composer_provenance_requirement() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		file_put_contents($project . '/devtools.yml', "virions:\n  - name: example.a\n    version: '1.0.0'\n");
		unlink($destination . '/example.a/devtools-provenance.json');
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('does not match this composer.lock');
		$this->builder()->build($project, $destination, $project . '/build');
	}

	public function test_unrelated_lock_metadata_does_not_prevent_sharing_identical_package() : void
	{
		$project = $this->project();
		$destination = $this->temporaryDirectory . '/prepared';
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination);
		$contents = file_get_contents($project . '/composer.lock');
		self::assertIsString($contents);
		file_put_contents($project . '/composer.lock', str_replace('"packages-dev":', '"_readme": ["another project lock"], "packages-dev":', $contents));
		$result = $this->builder()->build($project, $destination, $project . '/build');
		self::assertCount(2, $result->dependencies);
	}

	public function test_root_composer_files_bootstrap_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		file_put_contents($project . '/composer.json', '{"autoload":{"files":["src/functions.php"]}}');
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('autoload.files');
		$this->builder()->build($project, $this->temporaryDirectory . '/virions', $project . '/build');
	}

	public function test_root_composer_source_outside_plugin_src_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		file_put_contents($project . '/composer.json', '{"autoload":{"psr-4":{"Extra\\\\Library\\\\":"lib/"}}}');
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('autoload path lib/ is outside src/');
		$this->builder()->build($project, $this->temporaryDirectory . '/virions', $project . '/build');
	}

	public function test_simple_project_prepare_is_noop() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$destination = $this->temporaryDirectory . '/prepared';
		self::assertSame([], (new ComposerVirionPreparer($this->filesystem))->prepare($project, $destination));
		self::assertDirectoryDoesNotExist($destination);
	}

	public function test_root_mapping_under_src_must_match_axolotl_namespace_layout() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$this->json($project . '/composer.json', ['autoload' => ['psr-4' => ['Fixture\\Build\\Extra\\' => 'src/extra/']]]);
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('Axolotl-PM expects src/Extra');
		(new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
	}

	public function test_root_mapping_matching_axolotl_layout_requires_no_extra_loader() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$this->json($project . '/composer.json', ['autoload' => ['psr-4' => ['Fixture\\Build\\' => 'src/', 'Fixture\\Build\\Extra\\' => 'src/Extra/']]]);
		self::assertSame([], (new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared'));
	}

	/** @param array<string, mixed>|null $autoload */
	private function project(?array $autoload = null) : string
	{
		$project = $this->copyFixture('BuildPlugin');
		unlink($project . '/devtools.yml');
		$main = file_get_contents($project . '/src/Main.php');
		self::assertIsString($main);
		file_put_contents($project . '/src/Main.php', str_replace('Shared\\Virion', 'Example\\LibraryA', $main));
		$root = ['name' => 'example/plugin', 'require' => ['example/a' => '^1.0'], 'require-dev' => ['example/dev' => '*']];
		$this->json($project . '/composer.json', $root);
		$a = ['name' => 'example/a', 'version' => '1.0.0', 'source' => ['type' => 'git', 'url' => 'https://example.invalid/a', 'reference' => str_repeat('a', 40)], 'autoload' => $autoload ?? ['psr-4' => ['Example\\LibraryA\\' => 'src/']], 'require' => ['php' => '^8.1', 'example/b' => '^1.0'], 'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'Example\\LibraryA']]];
		$b = ['name' => 'example/b', 'version' => '1.1.0', 'autoload' => ['psr-4' => ['Example\\LibraryB\\' => 'src/']], 'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'Example\\LibraryB']]];
		ksort($root);
		$this->json($project . '/composer.lock', ['content-hash' => md5(json_encode($root, JSON_THROW_ON_ERROR)), 'packages' => [$a, $b], 'packages-dev' => [['name' => 'example/dev']]]);
		$this->filesystem->ensureDirectory($project . '/vendor/composer');
		$this->json($project . '/vendor/composer/installed.json', ['packages' => [$a, $b]]);
		foreach (['a' => $a, 'b' => $b] as $name => $package) {
			$this->filesystem->ensureDirectory($project . '/vendor/example/' . $name . '/src');
			$this->json($project . '/vendor/example/' . $name . '/composer.json', $package);
			file_put_contents($project . '/vendor/example/' . $name . '/LICENSE', 'Fixture license');
		}
		file_put_contents($project . '/vendor/example/a/src/Greeting.php', '<?php namespace Example\\LibraryA; final class Greeting { public static function message(): string { return \\Example\\LibraryB\\Greeting::message(); } }');
		file_put_contents($project . '/vendor/example/b/src/Greeting.php', '<?php namespace Example\\LibraryB; final class Greeting { public static function message(): string { return file_get_contents(__DIR__ . "/message.txt"); } }');
		file_put_contents($project . '/vendor/example/b/src/message.txt', 'composed resource');
		return $project;
	}

	/** @param array<string, mixed> $value */
	private function json(string $path, array $value) : void
	{
		file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}
}
