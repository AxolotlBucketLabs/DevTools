<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Support;

use AxolotlBucketLabs\DevTools\Support\FilesystemException;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use function realpath;
use const DIRECTORY_SEPARATOR;

final class PathTest extends TestCase
{
	public function test_windows_device_aliases_are_rejected_for_portable_paths() : void
	{
		foreach (['conin$', 'conout$', 'com0', 'lpt0', 'com¹', 'lpt³'] as $path) {
			try {
				Path::normalizeRelative($path);
				self::fail("Expected reserved device name {$path} to be rejected.");
			} catch (FilesystemException $error) {
				self::assertStringContainsString('reserved Windows device name', $error->getMessage());
			}
		}
	}

	public function test_windows_paths_are_compared_without_case_sensitivity() : void
	{
		if (DIRECTORY_SEPARATOR !== '\\') {
			self::markTestSkipped('Windows path comparison is platform-specific.');
		}

		self::assertTrue(Path::isInside('C:/Users/NhanAZ/project', 'C:/users/nhanaz/project/src/file.php'));
		self::assertFalse(Path::isInside('C:/Users/NhanAZ/project', 'C:/users/nhanaz/project-backup/src/file.php'));
	}

	public function test_windows_realpath_case_does_not_bypass_vendor_boundary_check() : void
	{
		if (DIRECTORY_SEPARATOR !== '\\') {
			self::markTestSkipped('Windows path comparison is platform-specific.');
		}

		$project = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'project';
		$parent = $project . DIRECTORY_SEPARATOR . 'VENDOR' . DIRECTORY_SEPARATOR . 'prepared';
		$this->filesystem->ensureDirectory($parent);
		$realProject = realpath($project);
		$realParent = realpath($parent);
		self::assertIsString($realProject);
		self::assertIsString($realParent);

		self::assertTrue(Path::isInside(Path::join($realProject, 'vendor'), $realParent));
	}
}
