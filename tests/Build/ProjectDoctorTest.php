<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\ProjectDoctor;
use AxolotlBucketLabs\DevTools\Build\VirionResolver;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\LocalizedException;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use LogicException;
use RuntimeException;
use Throwable;
use function file_put_contents;
use function realpath;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use const FILE_APPEND;

final class ProjectDoctorTest extends TestCase
{
	public function test_valid_project_without_dependencies_returns_its_manifest() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		$virions = $this->temporaryDirectory . '/virions';

		$result = $this->doctor()->check($project, $virions);

		self::assertFalse($result->validation->hasErrors());
		self::assertSame([], $result->validation->issues());
		self::assertSame('FixturePlugin', $result->requireManifest()->description->getName());
		self::assertSame('passed', $result->dependencyStatus);
		self::assertSame([], $result->virions);
		self::assertNull($result->error);
		self::assertDirectoryDoesNotExist($virions);
		self::assertDirectoryDoesNotExist($project . '/build');
	}

	public function test_validation_errors_stop_before_manifest_and_configuration_reads() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/src/Main.php', '<?php invalid syntax');
		file_put_contents($project . '/devtools.yml', "unknown: true\n");

		$result = $this->doctor()->check($project, $this->temporaryDirectory . '/virions', $project . '/missing.yml');

		self::assertTrue($result->validation->hasErrors());
		self::assertSame('plugin.source_invalid', $result->validation->issues()[0]->code);
		self::assertNull($result->manifest);
		self::assertNull($result->error);
		self::assertSame('not_run', $result->dependencyStatus);
		self::assertSame([], $result->virions);
		$this->expectException(LogicException::class);
		$result->requireManifest();
	}

	public function test_success_keeps_validation_warnings_and_resolved_virions() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$virion = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents($project . '/plugin.yml', "softdepend: [Optional, optional]\n", FILE_APPEND);

		$result = $this->doctor()->check($project, $this->temporaryDirectory . '/virions');

		self::assertFalse($result->validation->hasErrors());
		self::assertSame('plugin.dependency_duplicate', $result->validation->issues()[0]->code);
		self::assertSame('BuildFixture', $result->requireManifest()->description->getName());
		self::assertSame('passed', $result->dependencyStatus);
		self::assertNull($result->error);
		self::assertCount(1, $result->virions);
		self::assertSame('SharedVirion', $result->virions[0]->manifest->name);
		self::assertSame(realpath($virion), $result->virions[0]->location);
	}

	public function test_manifest_read_failure_keeps_dependencies_not_run() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		$manifestPath = $project . '/changed-plugin.yml';
		file_put_contents($manifestPath, "name: BrokenPlugin\nversion: 1.0.0\napi: 5.0.0\n");

		$result = $this->doctor()->check($project, $this->temporaryDirectory . '/virions', $manifestPath);

		self::assertFalse($result->validation->hasErrors());
		self::assertNull($result->manifest);
		self::assertSame([], $result->virions);
		self::assertSame('not_run', $result->dependencyStatus);
		self::assertInstanceOf(RuntimeException::class, $result->error);
		self::assertStringContainsString('Cannot parse plugin manifest ' . $manifestPath, $result->error->getMessage());
		self::assertNotNull($result->error->getPrevious());
	}

	public function test_invalid_configuration_keeps_manifest_and_marks_dependencies_failed() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/devtools.yml', "unknown: true\n");

		$result = $this->doctor()->check($project, $this->temporaryDirectory . '/virions');

		self::assertFalse($result->validation->hasErrors());
		self::assertSame('FixturePlugin', $result->requireManifest()->description->getName());
		self::assertSame('failed', $result->dependencyStatus);
		self::assertSame([], $result->virions);
		self::assertInstanceOf(BuildException::class, $result->error);
		self::assertStringContainsString('Unknown key "unknown"', $result->error->getMessage());
	}

	public function test_missing_dependency_keeps_validation_warnings_and_manifest() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		file_put_contents($project . '/plugin.yml', "softdepend: [Optional, optional]\n", FILE_APPEND);

		$result = $this->doctor()->check($project, $this->temporaryDirectory . '/virions');

		self::assertSame('plugin.dependency_duplicate', $result->validation->issues()[0]->code);
		self::assertSame('BuildFixture', $result->requireManifest()->description->getName());
		self::assertSame('failed', $result->dependencyStatus);
		self::assertSame([], $result->virions);
		self::assertInstanceOf(BuildException::class, $result->error);
		self::assertStringContainsString('no valid virion', $result->error->getMessage());
	}

	public function test_resolution_failure_preserves_original_localized_exception() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$error = new LocalizedException('cli.project_missing', ['project' => $project], true);
		$debug = new DebugLogger(static function (string $message) use ($error) : void {
			throw $error;
		});

		$result = $this->doctor($debug)->check($project, $this->temporaryDirectory . '/virions');

		self::assertSame($error, $result->error);
		self::assertTrue($result->error->isUsageError());
		self::assertSame('failed', $result->dependencyStatus);
		self::assertSame('BuildFixture', $result->requireManifest()->description->getName());
	}

	public function test_unexpected_validation_exception_propagates_unchanged() : void
	{
		$error = new RuntimeException('Unexpected validation I/O failure.');
		ProjectDoctorFailureStream::$error = $error;
		self::assertTrue(stream_wrapper_register('devtoolsdoctorfailure', ProjectDoctorFailureStream::class));
		try {
			$this->doctor()->check('devtoolsdoctorfailure://project', $this->temporaryDirectory . '/virions');
			self::fail('The validator exception must escape the project check.');
		} catch (Throwable $caught) {
			self::assertSame($error, $caught);
		} finally {
			stream_wrapper_unregister('devtoolsdoctorfailure');
		}
	}

	private function doctor(?DebugLogger $debug = null) : ProjectDoctor
	{
		[$reader, $validator] = $this->validationServices();
		$yaml = new YamlReader();

		return new ProjectDoctor(
			$validator,
			$reader,
			new BuildConfigResolver($yaml),
			new VirionResolver(
				new VirionDiscovery($this->filesystem),
				new VirionProjectFactory(new VirionManifestReader($yaml)),
				new VirionProjectSelector(),
				$debug,
			),
		);
	}
}

final class ProjectDoctorFailureStream
{
	public mixed $context;
	public static Throwable $error;

	public function url_stat(string $path, int $flags) : never
	{
		throw self::$error;
	}
}
