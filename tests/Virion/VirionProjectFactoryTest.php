<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use function file_put_contents;
use function rename;

final class VirionProjectFactoryTest extends TestCase
{
	public function test_manifest_symlink_is_rejected() : void
	{
		$project = $this->copyFixture('SharedVirion');
		$manifest = $project . '/virion.yml';
		$outside = $this->temporaryDirectory . '/external-virion.yml';
		rename($manifest, $outside);
		$this->createSymbolicLink($outside, $manifest);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('symbolic link');
		$this->factory()->open($project);
	}

	public function test_intermediate_antigen_symlink_is_rejected() : void
	{
		$project = $this->copyFixture('SharedVirion');
		$source = $project . '/src/Shared';
		$outside = $this->temporaryDirectory . '/external-source';
		rename($source, $outside);
		$this->createSymbolicLink($outside, $source);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('symbolic link');
		$this->factory()->open($project);
	}

	public function test_folder_manifest_name_is_read_even_when_another_field_is_invalid() : void
	{
		$project = $this->copyFixture('SharedVirion');
		file_put_contents($project . '/virion.yml', "name: StableName\nversion: INVALID\nantigen: Shared\\Virion\napi: 5.0.0\n");

		self::assertSame('StableName', $this->factory()->readFolderManifestName($project));
	}

	private function factory() : VirionProjectFactory
	{
		return new VirionProjectFactory(new VirionManifestReader(new YamlReader()));
	}
}
