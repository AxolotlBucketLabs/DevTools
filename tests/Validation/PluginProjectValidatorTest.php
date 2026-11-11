<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Validation;

use AxolotlBucketLabs\DevTools\Tests\TestCase;
use function array_map;
use function file_put_contents;

final class PluginProjectValidatorTest extends TestCase
{
	public function test_valid_project_has_no_errors() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		[, $validator] = $this->validationServices();

		self::assertFalse($validator->validate($project)->hasErrors());
	}

	/** @dataProvider invalidProjectProvider */
	public function test_invalid_projects_produce_actionable_codes(string $fixture, string $expectedCode) : void
	{
		$project = $this->copyFixture($fixture);
		[, $validator] = $this->validationServices();

		$result = $validator->validate($project);

		self::assertTrue($result->hasErrors());
		self::assertContains($expectedCode, array_map(static fn($issue) : string => $issue->code, $result->issues()));
		self::assertNotEmpty($result->issues()[0]->suggestion);
	}

	/** @return iterable<string, array{string, string}> */
	public static function invalidProjectProvider() : iterable
	{
		yield 'invalid YAML' => ['InvalidManifest', 'plugin.manifest_invalid'];
		yield 'missing required fields' => ['MissingFields', 'plugin.manifest_invalid'];
		yield 'missing main file' => ['MissingMain', 'plugin.main_missing'];
		yield 'namespace mismatch' => ['NamespaceMismatch', 'plugin.main_declaration_mismatch'];
		yield 'duplicate commands' => ['DuplicateManifest', 'plugin.duplicate_commands'];
	}

	public function test_duplicate_permissions_are_reported_independently() : void
	{
		$project = $this->copyFixture('DuplicateManifest');
		[, $validator] = $this->validationServices();

		$codes = array_map(static fn($issue) : string => $issue->code, $validator->validate($project)->issues());

		self::assertContains('plugin.duplicate_commands', $codes);
		self::assertContains('plugin.duplicate_permissions', $codes);
	}

	public function test_duplicate_keys_in_block_scalar_are_not_manifest_duplicates() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/plugin.yml', "name: FixturePlugin\nversion: 1.0.0\nmain: Fixture\\Plugin\\Main\nsrc-namespace-prefix: Fixture\\Plugin\napi: 5.0.0\ndescription: |\n  commands:\n    example: first\n    example: second\n");
		[, $validator] = $this->validationServices();

		self::assertNotContains('plugin.duplicate_commands', array_map(
			static fn($issue) : string => $issue->code,
			$validator->validate($project)->issues(),
		));
	}

	public function test_empty_api_list_is_rejected() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/plugin.yml', "name: FixturePlugin\nversion: 1.0.0\nmain: Fixture\\Plugin\\Main\nsrc-namespace-prefix: Fixture\\Plugin\napi: []\n");
		[, $validator] = $this->validationServices();

		self::assertContains('plugin.api_invalid', array_map(
			static fn($issue) : string => $issue->code,
			$validator->validate($project)->issues(),
		));
	}

	public function test_file_in_main_path_reports_missing_source_without_warning() : void
	{
		$project = $this->copyFixture('ValidPlugin');
		file_put_contents($project . '/plugin.yml', "name: FixturePlugin\nversion: 1.0.0\nmain: Fixture\\Plugin\\Nested\\Main\nsrc-namespace-prefix: Fixture\\Plugin\napi: 5.0.0\n");
		file_put_contents($project . '/src/Nested', 'not a directory');
		[, $validator] = $this->validationServices();

		self::assertContains('plugin.main_missing', array_map(
			static fn($issue) : string => $issue->code,
			$validator->validate($project)->issues(),
		));
	}
}
