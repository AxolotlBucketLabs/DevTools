<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

use AxolotlBucketLabs\DevTools\Support\YamlReader;
use pocketmine\plugin\PluginDescription;
use RuntimeException;
use Throwable;
use function restore_error_handler;
use function set_error_handler;

final class PluginManifestReader
{
	public function __construct(private readonly YamlReader $yamlReader) {}

	public function read(string $path) : PluginManifest
	{
		try {
			$raw = $this->yamlReader->read($path);
			$warning = null;
			set_error_handler(static function (int $severity, string $message) use (&$warning) : bool {
				$warning = $message;

				return true;
			});
			try {
				$description = new PluginDescription($raw);
			} finally {
				restore_error_handler();
			}
			if ($warning !== null) {
				throw new RuntimeException("Invalid plugin fields. {$warning}");
			}
		} catch (Throwable $error) {
			throw new RuntimeException("Cannot parse plugin manifest {$path}: {$error->getMessage()}", 0, $error);
		}

		return new PluginManifest($description, $raw, $path);
	}
}
