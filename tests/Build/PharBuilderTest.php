<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\VirionBuilder;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use Phar;
use PharFileInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash_file;
use function proc_close;
use function proc_open;
use function str_ends_with;
use function str_replace;
use function stream_get_contents;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const PHP_BINARY;

final class PharBuilderTest extends TestCase
{
	public function test_documented_shared_virion_example_builds_as_a_standalone_plugin() : void
	{
		$examples = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'examples';
		$project = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'HelloShared';
		$virion = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions' . DIRECTORY_SEPARATOR . 'SharedGreeting';
		$this->filesystem->copyTree($examples . DIRECTORY_SEPARATOR . 'HelloShared', $project);
		$this->filesystem->copyTree($examples . DIRECTORY_SEPARATOR . 'SharedGreeting', $virion);

		$result = $this->builder()->build(
			$project,
			dirname($virion),
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);

		$phar = new Phar($result->outputPath);
		self::assertSame('HelloShared', $result->pluginName);
		self::assertCount(1, $result->shadedVirions);
		self::assertStringNotContainsString(
			'DevToolsExample\\SharedGreeting',
			$phar['src/Main.php']->getContent(),
		);
		self::assertTrue(isset($phar['META-INF/virions/SharedGreeting/LICENSE']));
	}

	public function test_missing_declared_virion_explains_external_preparation() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virions:\n  - name: MissingVirion\n    version: 1.0.0\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('DevTools does not download external dependencies automatically');
		$this->expectExceptionMessage('https://github.com/AxolotlBucketLabs/DevTools/blob/main/docs/dependencies.md');
		$this->builder()->build(
			$project,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
	}

	public function test_build_contains_resources_and_shaded_virion() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$output = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build';
		$sourceBefore = file_get_contents($project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php');

		$result = $this->builder()->build($project, $virions, $output);

		self::assertFileExists($result->outputPath);
		self::assertCount(1, $result->shadedVirions);
		$target = $result->shadedVirions['Shared\Virion'];
		$phar = new Phar($result->outputPath);
		self::assertTrue(isset($phar['plugin.yml']));
		self::assertTrue(isset($phar['LICENSE']));
		self::assertSame('resource included', trim($phar['resources/message.txt']->getContent()));
		self::assertTrue(isset($phar['META-INF/virions/SharedVirion/LICENSE']));
		self::assertStringContainsString($target, $phar['src/Main.php']->getContent());
		$virionPath = 'src/' . str_replace('Fixture/Build/', '', str_replace('\\', '/', $target)) . '/Greeting.php';
		self::assertTrue(isset($phar[$virionPath]), $virionPath);
		self::assertStringNotContainsString('namespace Shared\Virion', $phar[$virionPath]->getContent());
		require_once 'phar://' . str_replace('\\', '/', $result->outputPath) . '/' . $virionPath;
		self::assertSame('shared', ($target . '\\Greeting')::message());
		self::assertSame($sourceBefore, file_get_contents($project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php'));

		$allPhp = '';
		foreach (new \RecursiveIteratorIterator($phar) as $entry) {
			if (!$entry instanceof PharFileInfo) {
				continue;
			}
			if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.php')) {
				$allPhp .= $entry->getContent();
			}
		}
		self::assertStringNotContainsString('Shared\Virion', $allPhp);
		self::assertStringNotContainsString(str_replace('\\', '/', $virions), $allPhp);
	}

	public function test_php_include_files_in_include_paths_are_shaded_and_run_from_a_clean_process() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/IncludeRuntime');
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->ensureDirectory($project . '/runtime');
		file_put_contents($project . '/runtime/message.inc', "<?php\nreturn \\Shared\\Virion\\Greeting::message();\n");
		file_put_contents($project . '/devtools.yml', "virions:\n  - SharedVirion\ninclude-paths:\n  - runtime\n");

		$result = $this->builder()->build($project, dirname($virion), $this->temporaryDirectory . '/build');
		$relative = 'src/' . str_replace('\\', '/', substr($result->shadedVirions['Shared\\Virion'], strlen('Fixture\\Build') + 1)) . '/Greeting.php';
		$script = $this->temporaryDirectory . '/run-include.php';
		file_put_contents($script, <<<'PHP'
<?php
$artifact = str_replace('\\', '/', $argv[1]);
require('phar://' . $artifact . '/' . $argv[2]);
$value = require('phar://' . $artifact . '/runtime/message.inc');
echo $value;
PHP);

		$process = proc_open(
			[PHP_BINARY, $script, $result->outputPath, $relative],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			$this->temporaryDirectory,
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		self::assertSame(0, proc_close($process), 'stdout: ' . (string) $stdout . ' stderr: ' . (string) $stderr);
		self::assertSame('shared', $stdout);
	}

	public function test_exact_antigen_constant_reference_is_not_rewritten() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/ConstantPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->ensureDirectory($project . '/runtime');
		file_put_contents($project . '/src/Constant.php', "<?php\nnamespace Shared;\nconst Virion = 42;\n");
		file_put_contents($project . '/runtime/value.php', "<?php\nreturn \\Shared\\Virion;\n");
		file_put_contents($project . '/devtools.yml', "virions:\n  - SharedVirion\ninclude-paths:\n  - runtime\n");

		$result = $this->builder()->build($project, $virions, $this->temporaryDirectory . '/build');
		$phar = new Phar($result->outputPath);
		self::assertStringContainsString('const Virion = 42', $phar['src/Constant.php']->getContent());
		self::assertStringContainsString('return \\Shared\\Virion', $phar['runtime/value.php']->getContent());
		self::assertStringNotContainsString($result->shadedVirions['Shared\\Virion'], $phar['runtime/value.php']->getContent());
	}

	public function test_dynamic_reflection_method_class_name_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/ReflectionPlugin');
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents(
			$project . '/src/Main.php',
			<<<'PHP'
<?php
declare(strict_types=1);

namespace Fixture\Build;

use pocketmine\plugin\PluginBase;

final class Main extends PluginBase
{
    public function message(): string
    {
        return (new \ReflectionMethod(objectOrMethod: getenv('TARGET_CLASS'), method: 'message'))->invoke(null);
    }
}
PHP,
		);
		file_put_contents($project . '/devtools.yml', "virions:\n  - SharedVirion\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic ReflectionMethod construction');
		$this->builder()->build($project, $this->temporaryDirectory . '/virions', $this->temporaryDirectory . '/build');
	}

	public function test_root_virion_resources_are_rejected_before_building_an_ambiguous_artifact() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->ensureDirectory($virion . '/resources');
		file_put_contents($virion . '/resources/default.txt', 'unsupported root resource');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('root resources');
		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_staging_rejects_a_source_name_that_changes_meaning_when_normalized() : void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			self::markTestSkipped('Literal backslashes are path separators on Windows.');
		}
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->removeTree($virions . '/SharedVirion/resources');
		$this->filesystem->ensureDirectory($project . '/resources/directory');
		file_put_contents($project . '/resources/directory\\file.txt', 'literal backslash');
		file_put_contents($project . '/resources/directory/file.txt', 'normal directory');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('backslash');
		$this->builder()->build($project, $virions, $this->temporaryDirectory . '/build');
	}

	public function test_staging_rejects_case_conflicting_archive_paths() : void
	{
		if (DIRECTORY_SEPARATOR !== '/') {
			self::markTestSkipped('Case-conflicting paths cannot coexist on the default Windows filesystem.');
		}
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->removeTree($virions . '/SharedVirion/resources');
		file_put_contents($project . '/resources/A.txt', 'upper');
		file_put_contents($project . '/resources/a.txt', 'lower');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('case-conflicting');
		$this->builder()->build($project, $virions, $this->temporaryDirectory . '/build');
	}

	public function test_existing_output_is_protected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$output = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build';
		$builder = $this->builder();
		$first = $builder->build($project, $virions, $output);
		$hash = hash_file('sha256', $first->outputPath);

		try {
			$builder->build($project, $virions, $output);
			self::fail('Expected existing output protection.');
		} catch (BuildException $error) {
			self::assertStringContainsString('Refusing to replace', $error->getMessage());
		}
		self::assertSame($hash, hash_file('sha256', $first->outputPath));
	}

	public function test_explicit_overwrite_replaces_completed_output_and_cleans_backups() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$output = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build';
		$builder = $this->builder();
		$first = $builder->build($project, $virions, $output);
		$firstHash = hash_file('sha256', $first->outputPath);
		file_put_contents($project . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'message.txt', 'updated resource');

		$second = $builder->build($project, $virions, $output, true);

		self::assertNotSame($firstHash, hash_file('sha256', $second->outputPath));
		$phar = new Phar($second->outputPath);
		self::assertSame('updated resource', $phar['resources/message.txt']->getContent());
		unset($phar);
		self::assertSame([], glob($output . DIRECTORY_SEPARATOR . '*.backup-*') ?: []);
		self::assertSame([], glob($output . DIRECTORY_SEPARATOR . '.devtools-stage-*') ?: []);
	}

	public function test_output_inside_source_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('inside plugin source');

		$this->builder()->build($project, $virions, $project . DIRECTORY_SEPARATOR . 'src');
	}

	public function test_overwrite_rejects_an_output_directory_without_moving_it() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$output = $this->temporaryDirectory . '/build';
		$destination = $output . '/BuildFixture.phar';
		$this->filesystem->ensureDirectory($destination);
		file_put_contents($destination . '/keep.txt', 'original');

		try {
			$this->builder()->build($project, dirname($virion), $output, true);
			self::fail('Expected a non-file output to be rejected.');
		} catch (BuildException $error) {
			self::assertStringContainsString('not a regular file', $error->getMessage());
		} finally {
			self::assertDirectoryExists($destination);
			self::assertSame('original', file_get_contents($destination . '/keep.txt'));
			self::assertSame([], glob($output . '/*.backup-*') ?: []);
		}
	}

	public function test_numeric_leading_virion_name_has_a_valid_shaded_namespace() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virion = $this->copyFixture('SharedVirion', 'virions/3d-library');
		file_put_contents($project . '/devtools.yml', "virions: [3d-library]\n");
		file_put_contents($virion . '/virion.yml', "name: 3d-library\nversion: 1.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n");

		$result = $this->builder()->build($project, dirname($virion), $this->temporaryDirectory . '/build');

		self::assertMatchesRegularExpression(
			'/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/D',
			$result->shadedVirions['Shared\\Virion'],
		);
		self::assertFileExists($result->outputPath);
	}

	public function test_numeric_leading_plugin_name_without_a_namespace_can_be_shaded() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/3dPlugin');
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents($project . '/plugin.yml', "name: 3dPlugin\nversion: 1.0.0\nmain: Main\napi: 5.0.0\n");
		file_put_contents($project . '/src/Main.php', "<?php\nclass Main extends \\pocketmine\\plugin\\PluginBase {}\n");

		$result = $this->builder()->build($project, dirname($virion), $this->temporaryDirectory . '/build');

		self::assertStringStartsWith('DevToolsShade\\_3dPlugin\\', $result->shadedVirions['Shared\\Virion']);
		self::assertFileExists($result->outputPath);
	}

	/** @dataProvider virionOutputCollisionProvider */
	public function test_virion_outputs_cannot_overwrite_plugin_files(string $relative, string $includes) : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->removeTree($virion . '/resources');
		$resource = $project . '/' . $relative;
		$this->filesystem->ensureDirectory(dirname($resource));
		file_put_contents($resource, 'plugin resource');
		file_put_contents($project . '/devtools.yml', $includes, FILE_APPEND);
		$output = $this->temporaryDirectory . '/build';
		$this->filesystem->ensureDirectory($output);
		file_put_contents($output . '/BuildFixture.phar', 'previous artifact');

		try {
			$this->builder()->build($project, dirname($virion), $output, true);
			self::fail('Expected colliding virion output to be rejected.');
		} catch (BuildException $error) {
			self::assertStringContainsString('collides with an existing', $error->getMessage());
		}
		self::assertSame('plugin resource', file_get_contents($resource));
		self::assertSame('previous artifact', file_get_contents($output . '/BuildFixture.phar'));
	}

	/** @return iterable<string, array{string, string}> */
	public static function virionOutputCollisionProvider() : iterable
	{
		yield 'metadata' => ['META-INF/virions/SharedVirion/LICENSE', "include-paths: [META-INF]\n"];
	}

	public function test_plugin_license_and_notice_variants_are_preserved() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$notices = ['LICENSE.txt', 'COPYING.md', 'NOTICE.md'];
		foreach ($notices as $notice) {
			file_put_contents($project . '/' . $notice, $notice . ' contents');
		}

		$result = $this->builder()->build($project, dirname($virion), $this->temporaryDirectory . '/build');
		$phar = new Phar($result->outputPath);

		foreach ($notices as $notice) {
			self::assertTrue(isset($phar[$notice]), $notice);
			self::assertSame($notice . ' contents', $phar[$notice]->getContent());
		}
	}

	public function test_include_path_may_not_overlap_build_output() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'devtools.yml',
			"virions:\n  - SharedVirion\ninclude-paths:\n  - build\n",
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('overlaps the build output');

		$this->builder()->build($project, $virions, $project . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_unknown_build_config_key_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virons:\n  - SharedVirion\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('Unknown key "virons"');

		$this->builder()->build(
			$project,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
	}

	public function test_sensitive_declared_include_path_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents($project . DIRECTORY_SEPARATOR . '.env.production', 'TOKEN=secret');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "include-paths:\n  - .env.production\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('Refusing sensitive include path');

		$this->builder()->build(
			$project,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
	}

	public function test_broken_folder_does_not_fall_back_to_same_name_phar_for_builds() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$broken = $virions . DIRECTORY_SEPARATOR . 'SharedVirion';
		$this->filesystem->ensureDirectory($broken);
		file_put_contents(
			$broken . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 1.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
		);
		$source = $this->copyFixture('SharedVirion', 'source/SharedVirion');
		$this->buildVirionPhar($source, $virions, 'SharedVirion');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('no valid virion with that name exists');
		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_replacing_a_virion_phar_allows_a_second_build_in_the_same_process() : void
	{
		$source = $this->copyFixture('SharedVirion', 'source/SharedVirion');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->buildVirionPhar($source, $virions, 'SharedVirion');
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$output = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build';
		$this->builder()->build($project, $virions, $output);

		file_put_contents(
			$source . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 2.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
		);
		file_put_contents(
			$source . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Greeting.php',
			"<?php\nnamespace Shared\\Virion;\nfinal class Greeting { public static function message(): string { return 'updated'; } }\n",
		);
		$this->buildVirionPhar($source, $virions, 'SharedVirion', true);

		$this->builder()->build($project, $virions, $output, true);
		self::assertFileExists($output . DIRECTORY_SEPARATOR . 'BuildFixture.phar');
	}

	public function test_explicit_include_paths_reject_hidden_and_temporary_roots() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->ensureDirectory($project . DIRECTORY_SEPARATOR . '.git');
		file_put_contents($project . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'config', 'private metadata');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'local.tmp', 'temporary content');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virions: [SharedVirion]\ninclude-paths: [.git, local.tmp]\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('hidden or temporary');
		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_duplicate_virion_names_are_ambiguous() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion-A');
		$this->copyFixture('SharedVirion', 'virions/SharedVirion-B');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('exists in multiple locations');

		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_versioned_virion_requirement_selects_highest_compatible_version() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'devtools.yml',
			"virions:\n  - name: SharedVirion\n    version: ^1.0.0\n",
		);
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$versionOne = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v1');
		$versionTwo = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v2');
		file_put_contents(
			$versionOne . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 1.7.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
		);
		file_put_contents(
			$versionTwo . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 2.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
		);
		$greeting = $versionOne . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared'
			. DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Greeting.php';
		$source = file_get_contents($greeting);
		self::assertIsString($source);
		file_put_contents($greeting, str_replace("return 'shared';", "return 'selected-1.7';", $source));

		$result = $this->builder()->build(
			$project,
			$virions,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
		$phar = new Phar($result->outputPath);
		$virionSource = '';
		foreach (new \RecursiveIteratorIterator($phar) as $entry) {
			if ($entry instanceof PharFileInfo && $entry->getFilename() === 'Greeting.php') {
				$virionSource .= $entry->getContent();
			}
		}

		self::assertStringContainsString('selected-1.7', $virionSource);
	}

	public function test_build_selection_skips_a_higher_php_incompatible_version() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virions:\n  - SharedVirion\n");
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$versionOne = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v1');
		$versionTwo = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v2');
		file_put_contents(
			$versionOne . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 1.7.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
		);
		file_put_contents(
			$versionTwo . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 2.0.0\nantigen: Shared\\Virion\napi: 5.0.0\ncomposer-platform:\n  php: '>=9.0'\n",
		);
		$greeting = $versionOne . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared'
			. DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Greeting.php';
		$source = file_get_contents($greeting);
		self::assertIsString($source);
		file_put_contents($greeting, str_replace("return 'shared';", "return 'selected-1.7';", $source));

		$result = $this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
		$phar = new Phar($result->outputPath);
		$virionSource = '';
		foreach (new \RecursiveIteratorIterator($phar) as $entry) {
			if ($entry instanceof PharFileInfo && $entry->getFilename() === 'Greeting.php') {
				$virionSource .= $entry->getContent();
			}
		}

		self::assertStringContainsString('selected-1.7', $virionSource);
	}

	public function test_incompatible_preferred_folder_does_not_fallback_to_a_phar() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virions:\n  - SharedVirion\n");
		$folder = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents(
			$folder . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 2.0.0\nantigen: Shared\\Virion\napi: 5.0.0\ncomposer-platform:\n  php: '>=9.0'\n",
		);
		$pharSource = $this->copyFixture('SharedVirion', 'source/SharedVirion');
		file_put_contents(
			$pharSource . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: SharedVirion\nversion: 1.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
		);
		$pharPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions' . DIRECTORY_SEPARATOR . 'SharedVirion.phar';
		$phar = new Phar($pharPath);
		$phar->buildFromDirectory($pharSource);
		$phar->setStub("<?php __HALT_COMPILER();\n");
		unset($phar);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('no valid virion with that name exists');
		$this->builder()->build(
			$project,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
	}

	public function test_plugin_namespace_overlapping_a_virion_is_rejected_before_shading() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'plugin.yml',
			"name: BuildFixture\nversion: 1.0.0\nmain: Shared\\Virion\\Main\napi: 5.0.0\nsrc-namespace-prefix: Shared\\Virion\n",
		);
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php',
			"<?php\ndeclare(strict_types=1);\nnamespace Shared\\Virion;\nfinal class Main {}\n",
		);
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('overlaps virion antigen');
		$this->builder()->build($project, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions', $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_virion_namespace_declarations_outside_antigen_are_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virions:\n  - SharedVirion\n");
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents(
			$virion . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Greeting.php',
			<<<'PHP'
<?php
namespace Shared\Virion {
    final class Greeting
    {
        public static function message(): string
        {
            return \GlobalHelpers\value();
        }
    }
}

namespace GlobalHelpers {
    function &value(): string
    {
        static $value = 'shared';
        return $value;
    }
}
PHP,
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('declaration GlobalHelpers\\value is outside antigen');
		$this->builder()->build(
			$project,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
	}

	#[DataProvider('pluginDeclarationOverlapProvider')]
	public function test_plugin_declarations_in_all_staged_php_sources_are_rejected(
		string $relativePath,
		string $configuration,
	) : void {
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$path = $project . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
		$parent = dirname($path);
		$this->filesystem->ensureDirectory($parent);
		file_put_contents($path, "<?php\nnamespace Shared\\Virion;\nfinal class Extra {}\n");
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', $configuration);

		$output = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build';
		$this->filesystem->ensureDirectory($output);
		$artifact = $output . DIRECTORY_SEPARATOR . 'BuildFixture.phar';
		file_put_contents($artifact, 'previous artifact');

		try {
			$this->builder()->build($project, $virions, $output, true);
			self::fail('Expected overlapping plugin declaration to be rejected.');
		} catch (BuildException $error) {
			self::assertStringContainsString('Plugin declaration Shared\\Virion\\Extra overlaps virion antigen', $error->getMessage());
		}
		self::assertSame('previous artifact', file_get_contents($artifact));
	}

	/** @return iterable<string, array{string, string}> */
	public static function pluginDeclarationOverlapProvider() : iterable
	{
		yield 'include path php file' => [
			'runtime/Extra.php',
			"virions:\n  - SharedVirion\ninclude-paths:\n  - runtime\n",
		];
		yield 'src include file' => [
			'src/Extra.inc',
			"virions:\n  - SharedVirion\n",
		];
	}

	public function test_plugin_class_declarations_overlapping_a_virion_are_rejected_before_shading() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virions:\n  - SharedVirion\n");
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->ensureDirectory($project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion');
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Custom.php',
			"<?php\nnamespace Shared\\Virion;\nfinal class Custom {}\n",
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('Plugin declaration Shared\\Virion\\Custom overlaps virion antigen');
		$this->builder()->build(
			$project,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
	}

	public function test_standalone_build_rejects_devtools_dependency() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'plugin.yml', "depend: [DevTools]\n", FILE_APPEND);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('cannot retain a DevTools');

		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_available_but_undeclared_virion_reference_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents($project . DIRECTORY_SEPARATOR . 'devtools.yml', "virions: []\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('development-only namespace');

		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_devtools_self_build_ignores_unrelated_available_virions() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugins/DevTools');
		$this->addDynamicDependencyInclude($project);
		$manifestPath = $project . DIRECTORY_SEPARATOR . 'plugin.yml';
		$manifest = file_get_contents($manifestPath);
		self::assertIsString($manifest);
		file_put_contents($manifestPath, str_replace('name: FixturePlugin', 'name: DevTools', $manifest));
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');

		$result = $this->builder()->build(
			$project,
			dirname($virion),
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);

		self::assertSame([], $result->shadedVirions);
		self::assertFileExists($result->outputPath);
	}

	public function test_non_devtools_build_still_rejects_unproven_dynamic_class_reference() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugins/RegularPlugin');
		$this->addDynamicDependencyInclude($project);
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		$this->builder()->build(
			$project,
			dirname($virion),
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build',
		);
	}

	public function test_direct_devtools_code_dependency_is_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$mainPath = $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php';
		$source = file_get_contents($mainPath);
		self::assertIsString($source);
		file_put_contents(
			$mainPath,
			str_replace(
				'use Shared\\Virion\\Greeting;',
				"use AxolotlBucketLabs\\DevTools\\DevTools;\nuse Shared\\Virion\\Greeting;",
				$source,
			),
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('development-only namespace axolotlbucketlabs\\devtools');

		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	public function test_overlapping_declared_antigens_are_rejected() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$nested = $this->copyFixture('SharedVirion', 'virions/NestedVirion');
		file_put_contents(
			$nested . DIRECTORY_SEPARATOR . 'virion.yml',
			"name: NestedVirion\nversion: 1.0.0\nantigen: Shared\\Virion\\Nested\napi: 5.0.0\n",
		);
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'devtools.yml',
			"virions:\n  - SharedVirion\n  - NestedVirion\n",
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('overlaps declared antigen');

		$this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
	}

	private function addDynamicDependencyInclude(string $project) : void
	{
		$dependency = $project . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'DynamicFactory.php';
		$this->filesystem->ensureDirectory(dirname($dependency));
		file_put_contents(
			$dependency,
			<<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture\BuildDependency;

final class DynamicFactory
{
    public static function create(): object
    {
        $className = \stdClass::class;

        return new $className();
    }
}
PHP,
		);
		file_put_contents(
			$project . DIRECTORY_SEPARATOR . 'devtools.yml',
			"include-paths:\n  - vendor/DynamicFactory.php\n",
		);
	}

	private function buildVirionPhar(string $source, string $output, string $name, bool $overwrite = false) : void
	{
		$yaml = new YamlReader();
		$manifestReader = new VirionManifestReader($yaml);
		(new VirionBuilder(
			$this->filesystem,
			$manifestReader,
			new VirionProjectFactory($manifestReader),
			new VirionClassScanner(new PhpSourceInspector()),
		))->build($source, $output, $overwrite, $name);
	}
}
