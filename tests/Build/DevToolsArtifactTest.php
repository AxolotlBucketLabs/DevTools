<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Support\TranslationKeys;

use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use Phar;
use function copy;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function md5;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function strlen;
use function var_export;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class DevToolsArtifactTest extends TestCase
{
	public function test_release_gate_accepts_parser_only_and_rejects_bundled_development_dependencies() : void
	{
		$root = dirname(__DIR__, 2);
		$result = $this->builder()->build($root, $this->temporaryDirectory . '/virions', $this->temporaryDirectory);
		[$exit, $output] = $this->validate($result->outputPath);
		self::assertSame(0, $exit, $output);

		$phar = new Phar($result->outputPath);
		foreach (Translator::supportedLocales() as $locale) {
			self::assertTrue(isset($phar["resources/lang/{$locale}.json"]));
			$catalog = json_decode($phar["resources/lang/{$locale}.json"]->getContent(), true, \AxolotlBucketLabs\DevTools\Support\JsonLimits::MAX_NESTING_DEPTH, JSON_THROW_ON_ERROR);
			self::assertIsArray($catalog);
			self::assertSame(Translator::fromResources($locale)->translate(TranslationKeys::VIRIONS_LOCAL_METADATA_MISMATCH), $catalog[TranslationKeys::VIRIONS_LOCAL_METADATA_MISMATCH] ?? null);
		}

		foreach (['vendor/phpunit/phpunit/src/Runner.php', 'vendor/axolotl-pm/pocketmine-mp/src/Server.php', 'vendor/composer/autoload_real.php'] as $extra) {
			$copy = $this->temporaryDirectory . '/extra-' . md5($extra) . '.phar';
			copy($result->outputPath, $copy);
			$phar = new Phar($copy);
			$phar[$extra] = '<?php';
			unset($phar);
			[$exit, $output] = $this->validate($copy);
			self::assertSame(1, $exit, $output);
			self::assertStringContainsString('unapproved bundled dependency path', $output);
			self::assertStringContainsString($extra, $output);
		}
	}

	public function test_artifact_validator_disables_php_object_decoding_for_manifest_yaml() : void
	{
		$root = dirname(__DIR__, 2);
		$result = $this->builder()->build($root, $this->temporaryDirectory . '/virions', $this->temporaryDirectory);
		$path = $this->temporaryDirectory . '/object-manifest.phar';
		copy($result->outputPath, $path);
		$phar = new Phar($path);
		$manifest = $phar['plugin.yml']->getContent();
		$class = 'DevToolsArtifactValidationPayload';
		$phar['plugin.yml'] = $manifest . "\naudit: !php/object O:" . strlen($class) . ':"' . $class . '":0:{}' . "\n";
		unset($phar);

		$marker = $this->temporaryDirectory . '/yaml-object-decoded';
		$prepend = $this->temporaryDirectory . '/prepend.php';
		file_put_contents($prepend, "<?php\nfinal class {$class} { public function __wakeup() : void { file_put_contents(" . var_export($marker, true) . ", 'woke'); } }\n");
		$process = proc_open(
			[
				PHP_BINARY,
				'-d',
				'yaml.decode_php=1',
				'-d',
				'auto_prepend_file=' . $prepend,
				dirname(__DIR__, 2) . '/bin/validate-artifact.php',
				$path,
			],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		self::assertSame(0, proc_close($process), $stdout . $stderr);
		self::assertFalse(file_exists($marker), 'The artifact validator must not unserialize YAML PHP objects.');
	}

	/** @return array{int, string} */
	private function validate(string $path) : array
	{
		$process = proc_open(
			[PHP_BINARY, dirname(__DIR__, 2) . '/bin/validate-artifact.php', $path],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertIsString($stdout);
		self::assertIsString($stderr);

		return [proc_close($process), $stdout . $stderr];
	}
}
