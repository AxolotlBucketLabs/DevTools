<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use function file_put_contents;
use function hash_file;
use const FILE_APPEND;

final class TransitiveVirionTest extends TestCase
{
	public function test_local_transitive_dependency_is_bundled_once() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$a = $this->copyFixture('SharedVirion', 'virions/A');
		file_put_contents($a . '/virion.yml', "virions:\n  - name: Dependency\n    version: ^1.0.0\n", FILE_APPEND);
		$this->dependency();
		$result = $this->builder()->build($project, $this->temporaryDirectory . '/virions', $project . '/build');
		self::assertCount(2, $result->shadedVirions);
		self::assertArrayHasKey('Example\\Dependency', $result->shadedVirions);
	}

	public function test_missing_transitive_dependency_reports_requiring_manifest() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$a = $this->copyFixture('SharedVirion', 'virions/A');
		file_put_contents($a . '/virion.yml', "virions: [MissingLibrary]\n", FILE_APPEND);
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('MissingLibrary is missing');
		$this->builder()->build($project, $this->temporaryDirectory . '/virions', $project . '/build');
	}

	public function test_incompatible_transitive_constraint_preserves_previous_artifact() : void
	{
		$project = $this->copyFixture('BuildPlugin');
		$a = $this->copyFixture('SharedVirion', 'virions/A');
		$this->dependency();
		$result = $this->builder()->build($project, $this->temporaryDirectory . '/virions', $project . '/build');
		$before = hash_file('sha256', $result->outputPath);
		file_put_contents($a . '/virion.yml', "virions:\n  - name: Dependency\n    version: ^2.0.0\n", FILE_APPEND);
		try {
			$this->builder()->build($project, $this->temporaryDirectory . '/virions', $project . '/build', true);
			self::fail('Expected transitive conflict.');
		} catch (BuildException $error) {
			self::assertStringContainsString('No version of virion Dependency satisfies', $error->getMessage());
		}
		self::assertSame($before, hash_file('sha256', $result->outputPath));
	}

	private function dependency() : void
	{
		$target = $this->temporaryDirectory . '/virions/B';
		$this->filesystem->ensureDirectory($target . '/src');
		file_put_contents($target . '/virion.yml', "name: Dependency\nversion: 1.0.0\nantigen: Example\\Dependency\nphp: 8.1\n");
		file_put_contents($target . '/src/Value.php', '<?php namespace Example\\Dependency; final class Value {}');
	}
}
