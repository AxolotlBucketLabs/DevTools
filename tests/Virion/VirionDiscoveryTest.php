<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\FilesystemException;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use function dirname;
use function file_put_contents;
use function is_dir;

final class VirionDiscoveryTest extends TestCase
{
	public function test_discover_does_not_create_a_missing_directory() : void
	{
		$directory = $this->temporaryDirectory . '/missing/virions';

		self::assertSame([], (new VirionDiscovery($this->filesystem))->discover($directory));
		self::assertFalse(is_dir($directory));
		self::assertFalse(is_dir(dirname($directory)));
	}

	public function test_discover_rejects_an_existing_non_directory_path() : void
	{
		$file = $this->temporaryDirectory . '/virions';
		file_put_contents($file, 'not a directory');

		$this->expectException(FilesystemException::class);
		$this->expectExceptionMessage('non-directory path');

		(new VirionDiscovery($this->filesystem))->discover($file);
	}
}
