<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use function in_array;
use function is_array;
use function is_file;
use function strtolower;
use const DIRECTORY_SEPARATOR;

final class DevToolsSelfPackaging
{
	private const PLUGIN_NAME = 'devtools';
	private const PHP_PARSER_SOURCE_PATH = 'vendor/nikic/php-parser/lib';
	private const PHP_PARSER_LICENSE_PATH = 'vendor/nikic/php-parser/LICENSE';

	public static function matches(string $pluginName, mixed $includePaths) : bool
	{
		return strtolower($pluginName) === self::PLUGIN_NAME
			&& is_array($includePaths)
			&& in_array(self::PHP_PARSER_SOURCE_PATH, $includePaths, true)
			&& in_array(self::PHP_PARSER_LICENSE_PATH, $includePaths, true);
	}

	public static function matchesProject(string $projectRoot) : bool
	{
		$manifestPath = $projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST;
		$configPath = $projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::DEVTOOLS_CONFIG;
		if (!is_file($manifestPath) || !is_file($configPath)) {
			return false;
		}

		$yamlReader = new YamlReader();
		$config = $yamlReader->read($configPath);
		$includePaths = $config['include-paths'] ?? null;
		if (!self::matches(self::PLUGIN_NAME, $includePaths)) {
			return false;
		}

		$manifest = (new PluginManifestReader($yamlReader))->read($manifestPath);

		return self::matches($manifest->description->getName(), $includePaths);
	}
}
