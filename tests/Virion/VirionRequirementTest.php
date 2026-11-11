<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use PHPUnit\Framework\Attributes\DataProvider;

final class VirionRequirementTest extends TestCase
{
	#[DataProvider('pathComponentNames')]
	public function test_requirement_rejects_path_component_names(string $name) : void
	{
		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Name cannot be a path component.');

		new VirionRequirement($name, '*', 'test');
	}

	/** @return array<string, array{string}> */
	public static function pathComponentNames() : array
	{
		return [
			'current directory' => ['.'],
			'parent directory' => ['..'],
		];
	}
}
