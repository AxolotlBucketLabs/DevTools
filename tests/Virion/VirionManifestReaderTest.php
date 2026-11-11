<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use PHPUnit\Framework\Attributes\DataProvider;
use function file_put_contents;

final class VirionManifestReaderTest extends TestCase
{
	#[DataProvider('pathComponentNames')]
	public function test_read_rejects_path_component_names(string $name) : void
	{
		$path = $this->writeManifest($name);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('name cannot be');
		$this->reader()->read($path);
	}

	#[DataProvider('pathComponentNames')]
	public function test_read_name_rejects_path_component_names(string $name) : void
	{
		$path = $this->writeManifest($name);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('name cannot be');
		$this->reader()->readName($path);
	}

	/** @return array<string, array{string}> */
	public static function pathComponentNames() : array
	{
		return [
			'current directory' => ['.'],
			'parent directory' => ['..'],
		];
	}

	private function reader() : VirionManifestReader
	{
		return new VirionManifestReader(new YamlReader());
	}

	private function writeManifest(string $name) : string
	{
		$path = $this->temporaryDirectory . '/virion.yml';
		file_put_contents($path, "name: '{$name}'\nversion: 1.0.0\nantigen: 'Shared\\Virion'\napi: 5.0.0\n");

		return $path;
	}
}
