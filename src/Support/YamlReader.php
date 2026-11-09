<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use function file_get_contents;
use function get_debug_type;
use function is_array;
use function is_file;
use function restore_error_handler;
use function set_error_handler;
use function yaml_parse;

final class YamlReader
{
	/**
	 * @return array<string, mixed>
	 */
	public function read(string $path) : array
	{
		if (!is_file($path)) {
			throw new YamlException("The YAML file does not exist: {$path}");
		}

		$contents = file_get_contents($path);
		if ($contents === false) {
			throw new YamlException("The YAML file could not be read: {$path}");
		}

		$warning = null;
		set_error_handler(static function (int $severity, string $message) use (&$warning) : bool {
			$warning = $message;

			return true;
		});
		try {
			$data = yaml_parse($contents);
		} finally {
			restore_error_handler();
		}
		if ($warning !== null) {
			throw new YamlException("YAML syntax error in {$path}: {$warning}");
		}
		if (!is_array($data)) {
			throw new YamlException(
				"The YAML root in {$path} must be a map. Found " . get_debug_type($data) . '.',
			);
		}

		/** @var array<string, mixed> $data */
		return $data;
	}
}
