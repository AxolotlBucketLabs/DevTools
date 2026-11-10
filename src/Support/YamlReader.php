<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use function file_get_contents;
use function get_debug_type;
use function ini_get;
use function ini_set;
use function is_array;
use function is_file;
use function restore_error_handler;
use function set_error_handler;
use function yaml_parse;

final class YamlReader
{
	private const PHP_OBJECT_DECODING_INI = 'yaml.decode_php';
	private const PHP_OBJECT_DECODING_DISABLED = '0';

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

		$previousObjectDecoding = ini_get(self::PHP_OBJECT_DECODING_INI);
		if ($previousObjectDecoding === false) {
			throw new YamlException('The YAML extension does not expose its PHP object decoding setting.');
		}
		if ($previousObjectDecoding !== self::PHP_OBJECT_DECODING_DISABLED
			&& ini_set(self::PHP_OBJECT_DECODING_INI, self::PHP_OBJECT_DECODING_DISABLED) === false) {
			throw new YamlException('PHP object decoding could not be disabled for YAML parsing.');
		}

		$warning = null;
		try {
			set_error_handler(static function (int $severity, string $message) use (&$warning) : bool {
				$warning = $message;

				return true;
			});
			try {
				$data = yaml_parse($contents);
			} finally {
				restore_error_handler();
			}
		} finally {
			if ($previousObjectDecoding !== self::PHP_OBJECT_DECODING_DISABLED
				&& ini_set(self::PHP_OBJECT_DECODING_INI, $previousObjectDecoding) === false) {
				throw new YamlException('The previous YAML PHP object decoding setting could not be restored.');
			}
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
