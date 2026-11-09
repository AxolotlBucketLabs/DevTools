<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\PhpStanConfigGenerator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function proc_close;
use function proc_open;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;
use function stream_get_contents;
use function substr_count;
use function symlink;
use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

final class PhpStanConfigGeneratorTest extends TestCase
{
	public function test_configuration_analyzes_only_plugin_source_and_discovers_server_and_declared_virions() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugin');
		$server = $this->copyFixture('PhpStanServer', 'server');
		$virionPath = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->ensureDirectory($server . DIRECTORY_SEPARATOR . 'generated');
		$this->filesystem->ensureDirectory($server . DIRECTORY_SEPARATOR . 'vendor');
		$dependency = $this->copyFixture('PhpStanDependency', 'dependency');
		$virion = (new VirionProjectFactory(new VirionManifestReader(new YamlReader())))->open($virionPath);

		$config = (new PhpStanConfigGenerator())->generate(
			$project,
			$server,
			[$virion],
			'4',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'phpstan-cache',
			[$dependency . DIRECTORY_SEPARATOR . 'src'],
		);

		self::assertStringContainsString("    level: 4\n", $config);
		self::assertStringContainsString($this->portable($project . DIRECTORY_SEPARATOR . 'src'), $config);
		self::assertStringContainsString($this->portable($server . DIRECTORY_SEPARATOR . 'src'), $config);
		self::assertStringContainsString($this->portable($server . DIRECTORY_SEPARATOR . 'generated'), $config);
		self::assertStringContainsString($this->portable($server . DIRECTORY_SEPARATOR . 'vendor'), $config);
		self::assertStringContainsString($this->portable($virion->sourceRoot), $config);
		self::assertStringContainsString($this->portable($dependency . DIRECTORY_SEPARATOR . 'src'), $config);
		self::assertSame(1, substr_count($config, "    paths:\n"));
	}

	public function test_cli_phpstan_configuration_keeps_phar_virion_source_for_the_analysis_process() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugin');
		$server = $this->copyFixture('PhpStanServer', 'server');
		$virionSource = $this->copyFixture('SharedVirion', 'source');
		$virions = $this->temporaryDirectory . '/virions';
		$this->filesystem->ensureDirectory($virions);
		$pharPath = $virions . '/SharedVirion.phar';
		$phar = new \Phar($pharPath);
		$phar->startBuffering();
		$phar->buildFromDirectory($virionSource);
		$phar->setStub("<?php __HALT_COMPILER();\n");
		$phar->setSignatureAlgorithm(\Phar::SHA256);
		$phar->stopBuffering();
		unset($phar);

		$configPath = $this->temporaryDirectory . '/phpstan-phar.neon';
		$process = proc_open(
			[
				PHP_BINARY,
				dirname(__DIR__, 2) . '/bin/devtools-phpstan-config.php',
				'--project=' . $project,
				'--server=' . $server,
				'--virions=' . $virions,
				'--level=0',
				'--out=' . $configPath,
				'--tmp-dir=' . $this->temporaryDirectory . '/phpstan-cache',
			],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			dirname(__DIR__, 2),
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), $stdout . $stderr);

		$config = (string) file_get_contents($configPath);
		self::assertStringContainsString($this->portable($pharPath), $config);
		self::assertStringNotContainsString('devtools-virion-', $config);

		$analysis = proc_open(
			[
				PHP_BINARY,
				dirname(__DIR__, 2) . '/vendor/phpstan/phpstan/phpstan',
				'analyse',
				'--configuration=' . $configPath,
				'--no-progress',
			],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			dirname(__DIR__, 2),
		);
		self::assertIsResource($analysis);
		fclose($pipes[0]);
		$analysisOutput = stream_get_contents($pipes[1]);
		$analysisError = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		self::assertSame(0, proc_close($analysis), $analysisOutput . $analysisError);
	}

	#[DataProvider('validLevelProvider')]
	public function test_supported_levels_are_accepted(string $level) : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugin');
		$server = $this->copyFixture('PhpStanServer', 'server');

		$config = (new PhpStanConfigGenerator())->generate(
			$project,
			$server,
			[],
			$level,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cache',
		);

		self::assertStringContainsString("    level: {$level}\n", $config);
	}

	/** @return iterable<string, array{string}> */
	public static function validLevelProvider() : iterable
	{
		yield 'loosest' => ['0'];
		yield 'recommended start' => ['4'];
		yield 'strictest number' => ['10'];
		yield 'maximum alias' => ['max'];
	}

	#[DataProvider('invalidLevelProvider')]
	public function test_invalid_or_disabled_level_is_rejected_by_the_generator(string $level) : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugin');
		$server = $this->copyFixture('PhpStanServer', 'server');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('Invalid PHPStan level');

		(new PhpStanConfigGenerator())->generate(
			$project,
			$server,
			[],
			$level,
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cache',
		);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidLevelProvider() : iterable
	{
		yield 'disabled belongs to action gate' => ['off'];
		yield 'negative' => ['-1'];
		yield 'too high' => ['11'];
		yield 'leading zero' => ['04'];
		yield 'empty' => [''];
	}

	public function test_missing_server_source_has_an_actionable_error() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugin');
		$server = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'server';
		$this->filesystem->ensureDirectory($server);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('server source directory is missing');

		(new PhpStanConfigGenerator())->generate(
			$project,
			$server,
			[],
			'4',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cache',
		);
	}

	public function test_missing_additional_scan_directory_has_an_actionable_error() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugin');
		$server = $this->copyFixture('PhpStanServer', 'server');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('additional PHPStan scan directory is missing');

		(new PhpStanConfigGenerator())->generate(
			$project,
			$server,
			[],
			'4',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cache',
			[$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'missing-dependency'],
		);
	}

	public function test_generated_configuration_accepts_percent_in_the_project_path() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugin%prod%');
		$server = $this->copyFixture('PhpStanServer', 'server');
		$config = (new PhpStanConfigGenerator())->generate(
			$project,
			$server,
			[],
			'0',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cache',
		);
		$configPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'phpstan.neon';
		file_put_contents($configPath, $config);
		$process = proc_open(
			[PHP_BINARY, dirname(__DIR__, 2) . '/vendor/phpstan/phpstan/phpstan', '--no-progress', '--configuration=' . $configPath],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			dirname(__DIR__, 2),
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);

		self::assertSame(0, $exit, $stdout . $stderr);
	}

	public function test_symbolic_link_plugin_source_is_rejected() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugin');
		$server = $this->copyFixture('PhpStanServer', 'server');
		$source = $project . DIRECTORY_SEPARATOR . 'src';
		$outside = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'outside';
		rename($source, $outside);

		set_error_handler(static fn() : bool => true);
		try {
			$linked = symlink($outside, $source);
		} finally {
			restore_error_handler();
		}
		if (!$linked) {
			self::markTestSkipped('Creating directory symlinks is not permitted on this system.');
		}

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('symbolic-link plugin source');

		(new PhpStanConfigGenerator())->generate(
			$project,
			$server,
			[],
			'4',
			$this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cache',
		);
	}

	private function portable(string $path) : string
	{
		return str_replace('\\', '/', $path);
	}
}
