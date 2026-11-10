<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use Throwable;
use function array_map;
use function file_exists;
use function is_file;
use function rename;
use function str_replace;
use function strtolower;
use function unlink;

final class ArtifactSetInstaller
{
	/**
	 * @param list<string> $outputs
	 */
	public function assertDestinations(array $outputs, bool $overwrite) : void
	{
		$seen = [];
		foreach ($outputs as $output) {
			$key = strtolower(str_replace('\\', '/', $output));
			if (isset($seen[$key])) {
				throw new BuildException("Artifact output is declared more than once: {$output}");
			}
			$seen[$key] = true;
			if (Filesystem::isLink($output) || (file_exists($output) && !is_file($output))) {
				throw new BuildException("Build output is not a regular file: {$output}.");
			}
			if (file_exists($output) && !$overwrite) {
				throw new BuildException("Refusing to replace existing build {$output}. Run the build again with overwrite explicitly enabled.");
			}
		}
	}

	/**
	 * @param list<array{temporary: string, output: string}> $artifacts
	 */
	public function install(array $artifacts, bool $overwrite) : void
	{
		if ($artifacts === []) {
			throw new BuildException('Cannot install an empty artifact set.');
		}
		$this->assertDestinations(array_map(static fn(array $artifact) : string => $artifact['output'], $artifacts), $overwrite);
		foreach ($artifacts as $artifact) {
			if (Filesystem::isLink($artifact['temporary']) || !is_file($artifact['temporary'])) {
				throw new BuildException("Completed artifact is missing or not a regular file: {$artifact['temporary']}");
			}
		}

		/** @var array<string, string> $backups */
		$backups = [];
		$installed = [];
		try {
			foreach ($artifacts as $artifact) {
				$output = $artifact['output'];
				if (!file_exists($output)) {
					continue;
				}
				$backup = $output . '.backup-' . RandomToken::pathSuffix();
				if (!rename($output, $backup)) {
					throw new BuildException("Cannot move existing output aside before replacing the artifact set: {$output}");
				}
				$backups[$output] = $backup;
			}
			foreach ($artifacts as $artifact) {
				if (!rename($artifact['temporary'], $artifact['output'])) {
					throw new BuildException("Cannot install completed artifact: {$artifact['output']}");
				}
				$installed[] = $artifact['output'];
			}
		} catch (Throwable $error) {
			foreach ($installed as $output) {
				if (file_exists($output) && !unlink($output)) {
					throw new BuildException(
						$error->getMessage() . " The new artifact could not be removed during rollback: {$output}",
						0,
						$error,
					);
				}
			}
			foreach ($backups as $output => $backup) {
				if (file_exists($backup) && !rename($backup, $output)) {
					throw new BuildException(
						$error->getMessage() . " The previous artifact could not be restored: {$backup}",
						0,
						$error,
					);
				}
			}
			throw $error instanceof BuildException
				? $error
				: new BuildException("Artifact set installation failed: {$error->getMessage()}", 0, $error);
		}

		foreach ($backups as $backup) {
			if (file_exists($backup) && !unlink($backup)) {
				throw new BuildException("Artifact set installed, but the previous backup could not be removed: {$backup}");
			}
		}
	}
}
