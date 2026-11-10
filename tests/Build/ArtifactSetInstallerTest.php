<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\ArtifactSetInstaller;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function restore_error_handler;
use function set_error_handler;

final class ArtifactSetInstallerTest extends TestCase
{
	public function test_single_artifact_is_installed_without_changing_its_bytes() : void
	{
		$temporary = $this->temporaryDirectory . '/completed.phar';
		$output = $this->temporaryDirectory . '/output.phar';
		file_put_contents($temporary, 'completed artifact');

		(new ArtifactSetInstaller())->install([['temporary' => $temporary, 'output' => $output]], false);

		self::assertSame('completed artifact', file_get_contents($output));
		self::assertFileDoesNotExist($temporary);
	}

	public function test_single_artifact_overwrite_replaces_output_and_removes_backup() : void
	{
		$temporary = $this->temporaryDirectory . '/completed.phar';
		$output = $this->temporaryDirectory . '/output.phar';
		file_put_contents($temporary, 'completed artifact');
		file_put_contents($output, 'previous artifact');

		(new ArtifactSetInstaller())->install([['temporary' => $temporary, 'output' => $output]], true);

		self::assertSame('completed artifact', file_get_contents($output));
		self::assertFileDoesNotExist($temporary);
		self::assertSame([], glob($output . '.backup-*'));
	}

	public function test_install_rechecks_destination_created_after_preflight() : void
	{
		$temporary = $this->temporaryDirectory . '/completed.phar';
		$output = $this->temporaryDirectory . '/output.phar';
		file_put_contents($temporary, 'completed artifact');
		$installer = new ArtifactSetInstaller();
		$installer->assertDestinations([$output], false);
		file_put_contents($output, 'another artifact');

		try {
			$installer->install([['temporary' => $temporary, 'output' => $output]], false);
			self::fail('Expected overwrite protection to be rechecked.');
		} catch (BuildException $error) {
			self::assertSame("Refusing to replace existing build {$output}. Run the build again with overwrite explicitly enabled.", $error->getMessage());
		}
		self::assertSame('another artifact', file_get_contents($output));
		self::assertSame('completed artifact', file_get_contents($temporary));
		self::assertSame([], glob($output . '.backup-*'));
	}

	public function test_directory_destination_is_rejected_without_moving_it() : void
	{
		$temporary = $this->temporaryDirectory . '/completed.phar';
		$output = $this->temporaryDirectory . '/output.phar';
		file_put_contents($temporary, 'completed artifact');
		$this->filesystem->ensureDirectory($output);
		file_put_contents($output . '/keep.txt', 'original');

		try {
			(new ArtifactSetInstaller())->install([['temporary' => $temporary, 'output' => $output]], true);
			self::fail('Expected a directory destination to be rejected.');
		} catch (BuildException $error) {
			self::assertSame("Build output is not a regular file: {$output}.", $error->getMessage());
		}
		self::assertSame('original', file_get_contents($output . '/keep.txt'));
		self::assertSame('completed artifact', file_get_contents($temporary));
		self::assertSame([], glob($output . '.backup-*'));
	}

	public function test_missing_completed_artifact_preserves_existing_output() : void
	{
		$temporary = $this->temporaryDirectory . '/missing.phar';
		$output = $this->temporaryDirectory . '/output.phar';
		file_put_contents($output, 'previous artifact');

		try {
			(new ArtifactSetInstaller())->install([['temporary' => $temporary, 'output' => $output]], true);
			self::fail('Expected a missing completed artifact to be rejected.');
		} catch (BuildException $error) {
			self::assertSame("Completed artifact is missing or not a regular file: {$temporary}", $error->getMessage());
		}
		self::assertSame('previous artifact', file_get_contents($output));
		self::assertSame([], glob($output . '.backup-*'));
	}

	public function test_failed_single_artifact_install_restores_existing_output() : void
	{
		$output = $this->temporaryDirectory . '/output.phar';
		file_put_contents($output, 'previous artifact');
		// Aliasing the temporary path makes it disappear when the old output is backed up.
		set_error_handler(static fn() : bool => true);
		try {
			(new ArtifactSetInstaller())->install([['temporary' => $output, 'output' => $output]], true);
			self::fail('Expected installation to fail after backing up the old output.');
		} catch (BuildException $error) {
			self::assertSame("Cannot install completed artifact: {$output}", $error->getMessage());
		} finally {
			restore_error_handler();
		}
		self::assertSame('previous artifact', file_get_contents($output));
		self::assertSame([], glob($output . '.backup-*'));
	}

	public function test_symbolic_link_destination_is_rejected_without_changing_target() : void
	{
		$temporary = $this->temporaryDirectory . '/completed.phar';
		$output = $this->temporaryDirectory . '/output.phar';
		$target = $this->temporaryDirectory . '/target.phar';
		file_put_contents($temporary, 'completed artifact');
		file_put_contents($target, 'previous artifact');
		$this->createSymbolicLink($target, $output);

		try {
			(new ArtifactSetInstaller())->install([['temporary' => $temporary, 'output' => $output]], true);
			self::fail('Expected a symbolic-link destination to be rejected.');
		} catch (BuildException $error) {
			self::assertSame("Build output is not a regular file: {$output}.", $error->getMessage());
		}
		self::assertSame('previous artifact', file_get_contents($target));
		self::assertSame('completed artifact', file_get_contents($temporary));
	}
}
