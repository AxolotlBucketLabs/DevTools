<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Command;

use AxolotlBucketLabs\DevTools\Command\BuildWorkerApplication;

use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use function file_put_contents;
use function implode;
use function str_replace;
use const FILE_APPEND;

final class BuildWorkerApplicationTest extends TestCase
{
	public function test_doctor_continues_after_validation_and_dependency_failures() : void
	{
		$invalid = $this->copyFixture('ValidPlugin', 'plugins/Invalid');
		file_put_contents($invalid . '/src/Main.php', '<?php invalid syntax');
		$missing = $this->copyFixture('BuildPlugin', 'plugins/Missing');
		$valid = $this->copyFixture('ValidPlugin', 'plugins/Valid');
		$invalid = str_replace('\\', '/', $invalid);
		$missing = str_replace('\\', '/', $missing);
		$valid = str_replace('\\', '/', $valid);

		$result = BuildWorkerApplication::run('doctor', $this->paths());

		self::assertTrue($result['success']);
		self::assertSame([], $result['debug']);
		self::assertSame('Checking ' . $invalid, $result['messages'][0]);
		self::assertStringContainsString('plugin.source_invalid', $result['messages'][1]);
		self::assertSame('Checking ' . $missing, $result['messages'][2]);
		self::assertStringContainsString('Dependency check failed for ' . $missing, $result['messages'][3]);
		self::assertSame('Checking ' . $valid, $result['messages'][4]);
		self::assertSame(Translator::fromResources()->translate(TranslationKeys::DOCTOR_PASSED, ['count' => 0]), $result['messages'][5]);
		self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/build');
	}

	public function test_doctor_keeps_localized_summary_and_original_issue_format() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/Source');
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		file_put_contents($project . '/plugin.yml', "softdepend: [Optional, optional]\n", FILE_APPEND);
		$project = str_replace('\\', '/', $project);
		[, $validator] = $this->validationServices();
		$translator = Translator::fromResources('vie');

		$result = BuildWorkerApplication::run('doctor', $this->paths() + ['selection' => 'BuildFixture', 'locale' => 'vie']);

		self::assertTrue($result['success']);
		self::assertSame([
			$translator->translate(TranslationKeys::DOCTOR_CHECKING, ['project' => $project]),
			$validator->validate($project)->issues()[0]->format(),
			$translator->translate(TranslationKeys::DOCTOR_PASSED, ['count' => 1]),
		], $result['messages']);
	}

	public function test_doctor_keeps_localized_dependency_failure_as_completed_operation() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'plugins/Source');
		file_put_contents($project . '/devtools.yml', "unknown: true\n");
		$project = str_replace('\\', '/', $project);
		$translator = Translator::fromResources('vie');

		$result = BuildWorkerApplication::run('doctor', $this->paths() + ['selection' => 'FixturePlugin', 'locale' => 'vie']);

		self::assertTrue($result['success']);
		self::assertSame($translator->translate(TranslationKeys::DOCTOR_CHECKING, ['project' => $project]), $result['messages'][0]);
		self::assertStringContainsString('Unknown key "unknown"', $result['messages'][1]);
		self::assertStringNotContainsString(TranslationKeys::COMMAND_FAILED, implode("\n", $result['messages']));
		self::assertCount(2, $result['messages']);
	}

	public function test_empty_doctor_selection_reports_no_projects() : void
	{
		$this->filesystem->ensureDirectory($this->temporaryDirectory . '/plugins');

		$result = BuildWorkerApplication::run('doctor', $this->paths());

		self::assertSame([
			'success' => true,
			'messages' => [Translator::fromResources()->translate(TranslationKeys::DOCTOR_NONE, ['directory' => $this->temporaryDirectory . '/plugins'])],
			'debug' => [],
		], $result);
	}

	public function test_missing_paths_precede_invalid_operation_payloads() : void
	{
		$translator = Translator::fromResources('vie');
		foreach (['doctor', 'build', 'extract'] as $operation) {
			$result = BuildWorkerApplication::run($operation, ['selection' => false, 'locale' => 'vie']);

			self::assertFalse($result['success']);
			self::assertSame([$translator->translate(TranslationKeys::COMMAND_FAILED, [
				'error' => $translator->translate(TranslationKeys::WORKER_PATH_MISSING, ['key' => 'pluginsDirectory']),
			])], $result['messages']);
		}
	}

	/** @return array{pluginsDirectory: string, virionsDirectory: string, buildDirectory: string} */
	private function paths() : array
	{
		return [
			'pluginsDirectory' => $this->temporaryDirectory . '/plugins',
			'virionsDirectory' => $this->temporaryDirectory . '/virions',
			'buildDirectory' => $this->temporaryDirectory . '/build',
		];
	}
}
