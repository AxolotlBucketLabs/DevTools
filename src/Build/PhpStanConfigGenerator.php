<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use function array_keys;
use function implode;
use function is_dir;
use function json_encode;
use function preg_match;
use function realpath;
use function str_replace;
use function trim;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class PhpStanConfigGenerator
{
	private const MIN_LEVEL = 0;
	private const MAX_LEVEL = 10;
	private const LEVEL_PATTERN = '/^(?:0|[1-9][0-9]*)$/D';

	/**
	 * @param list<VirionProject> $virions
	 * @param list<string>        $additionalScanDirectories
	 */
	public function generate(
		string $projectRoot,
		string $serverRoot,
		array $virions,
		string $level,
		string $temporaryDirectory,
		array $additionalScanDirectories = [],
	) : string {
		$level = $this->normalizeLevel($level);
		$projectRoot = $this->requireDirectory($projectRoot, 'plugin project');
		$serverRoot = $this->requireDirectory($serverRoot, 'server source');
		$projectSource = $this->requireDirectory($projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY, 'plugin source');
		$serverSource = $this->requireDirectory($serverRoot . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY, 'server source');

		$scanDirectories = [$this->portable($serverSource) => $serverSource];
		foreach (['generated', 'vendor'] as $optional) {
			$path = $serverRoot . DIRECTORY_SEPARATOR . $optional;
			if (Filesystem::isLink($path)) {
				throw new BuildException("Refusing symbolic-link server {$optional} directory: {$path}");
			}
			if (is_dir($path)) {
				$real = realpath($path);
				if ($real === false) {
					throw new BuildException("Cannot resolve server {$optional} directory: {$path}");
				}
				$scanDirectories[$this->portable($real)] = $real;
			}
		}

		foreach ($virions as $virion) {
			if (Filesystem::isLink($virion->sourceRoot) || !is_dir($virion->sourceRoot)) {
				throw new BuildException(
					"Cannot analyze virion {$virion->manifest->name}: source directory is missing or is a symbolic link at {$virion->sourceRoot}.",
				);
			}
			$scanDirectories[$this->portable($virion->sourceRoot)] = $virion->sourceRoot;
		}

		foreach ($additionalScanDirectories as $directory) {
			$real = $this->requireDirectory($directory, 'additional PHPStan scan');
			$scanDirectories[$this->portable($real)] = $real;
		}

		$temporaryDirectory = trim($temporaryDirectory);
		if ($temporaryDirectory === '') {
			throw new BuildException('PHPStan temporary directory may not be empty.');
		}

		$lines = [
			'parameters:',
			"    level: {$level}",
			'    paths:',
			'        - ' . $this->quote($projectSource),
			'    scanDirectories:',
		];
		foreach (array_keys($scanDirectories) as $directory) {
			$lines[] = '        - ' . $this->quote($directory);
		}
		$lines[] = '    tmpDir: ' . $this->quote($temporaryDirectory);
		$lines[] = '';

		return implode("\n", $lines);
	}

	private function normalizeLevel(string $level) : string
	{
		$level = trim($level);
		if ($level === 'max') {
			return $level;
		}
		if (preg_match(self::LEVEL_PATTERN, $level) !== 1 || (int) $level < self::MIN_LEVEL || (int) $level > self::MAX_LEVEL) {
			throw new BuildException(
				"Invalid PHPStan level \"{$level}\". Use a number from " . self::MIN_LEVEL . ' through ' . self::MAX_LEVEL . ', or max. To disable analysis, omit the phpstan input or set it to off before invoking this generator.',
			);
		}

		return $level;
	}

	private function requireDirectory(string $path, string $description) : string
	{
		if (Filesystem::isLink($path)) {
			throw new BuildException("Refusing symbolic-link {$description} directory: {$path}");
		}
		if (!is_dir($path)) {
			throw new BuildException("Cannot run PHPStan: {$description} directory is missing at {$path}.");
		}
		$real = realpath($path);
		if ($real === false) {
			throw new BuildException("Cannot resolve {$description} directory: {$path}");
		}

		return $real;
	}

	private function quote(string $path) : string
	{
		return json_encode(str_replace('%', '%%', $this->portable($path)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}

	private function portable(string $path) : string
	{
		return str_replace('\\', '/', $path);
	}
}
