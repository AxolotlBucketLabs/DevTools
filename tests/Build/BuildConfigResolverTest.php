<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use function file_put_contents;

final class BuildConfigResolverTest extends TestCase
{
	public function test_case_conflicting_include_paths_are_rejected() : void
	{
		file_put_contents(
			$this->temporaryDirectory . '/devtools.yml',
			"include-paths:\n  - Assets\n  - assets\n",
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('case-conflicting');

		(new BuildConfigResolver(new YamlReader()))->resolve($this->temporaryDirectory, 'Fixture');
	}
}
