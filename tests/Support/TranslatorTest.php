<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Support;

use AxolotlBucketLabs\DevTools\Support\TranslationKeys;

use AxolotlBucketLabs\DevTools\Support\Translator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use function array_keys;
use function dirname;
use function file_get_contents;
use function json_decode;
use function preg_match_all;
use function sort;
use const JSON_THROW_ON_ERROR;

final class TranslatorTest extends TestCase
{
	private const UNKNOWN_TRANSLATION_KEY = 'unknown.key';

	public function test_all_axolotl_locales_have_a_catalog_and_a_translated_runtime_message() : void
	{
		$english = Translator::fromResources(Translator::DEFAULT_LOCALE);
		$englishHelp = $english->translate(TranslationKeys::CLI_HELP);
		foreach (Translator::supportedLocales() as $locale) {
			$translator = Translator::fromResources($locale);

			self::assertSame($locale, $translator->locale());
			self::assertNotSame(TranslationKeys::RUNTIME_READY, $translator->translate(TranslationKeys::RUNTIME_READY));
			self::assertStringContainsString('DevTools', $translator->translate(TranslationKeys::CLI_VERSION, ['version' => '3.3.1']));
			$loadFailure = $translator->translate(TranslationKeys::RUNTIME_PLUGIN_LOAD_FAILED, ['project' => 'ExamplePlugin', 'error' => 'Technical parser detail']);
			$manifestFailure = $translator->translate(TranslationKeys::RUNTIME_PLUGIN_MANIFEST_FAILED, ['project' => 'ExamplePlugin', 'error' => 'Technical parser detail']);
			$requirementsFailure = $translator->translate(TranslationKeys::RUNTIME_SHARED_REQUIREMENTS_FAILED, ['project' => 'ExamplePlugin', 'error' => 'Technical parser detail']);
			$duplicateSources = $translator->translate(TranslationKeys::RUNTIME_DUPLICATE_SOURCES, ['name' => 'ExamplePlugin']);
			$missingVirion = $translator->translate(TranslationKeys::VIRIONS_REQUIREMENT_MISSING, ['name' => 'ExampleVirion', 'requirements' => '^1.0.0 from ExamplePlugin']);
			self::assertStringContainsString('Technical parser detail', $loadFailure);
			self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $loadFailure);
			self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $manifestFailure);
			self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $requirementsFailure);
			self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $duplicateSources);
			self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $missingVirion);
			if ($locale !== Translator::DEFAULT_LOCALE) {
				self::assertNotSame($englishHelp, $translator->translate(TranslationKeys::CLI_HELP));
				foreach ([
					TranslationKeys::RUNTIME_PLUGIN_LOAD_FAILED => $loadFailure,
					TranslationKeys::RUNTIME_PLUGIN_MANIFEST_FAILED => $manifestFailure,
					TranslationKeys::RUNTIME_SHARED_REQUIREMENTS_FAILED => $requirementsFailure,
					TranslationKeys::RUNTIME_DUPLICATE_SOURCES => $duplicateSources,
					TranslationKeys::VIRIONS_REQUIREMENT_MISSING => $missingVirion,
				] as $key => $message) {
					$parameters = match ($key) {
						TranslationKeys::RUNTIME_DUPLICATE_SOURCES => ['name' => 'ExamplePlugin'],
						TranslationKeys::VIRIONS_REQUIREMENT_MISSING => ['name' => 'ExampleVirion', 'requirements' => '^1.0.0 from ExamplePlugin'],
						default => ['project' => 'ExamplePlugin', 'error' => 'Technical parser detail'],
					};
					self::assertNotSame($english->translate($key, $parameters), $message, "{$locale} did not translate {$key}");
				}
			}
		}
	}

	public function test_locale_aliases_and_english_fallback_work() : void
	{
		self::assertSame('chs', Translator::fromResources('zh-CN')->locale());
		self::assertSame('vie', Translator::fromResources('vi')->locale());
		self::assertSame(self::UNKNOWN_TRANSLATION_KEY, Translator::fromResources('vie')->translate(self::UNKNOWN_TRANSLATION_KEY, ['value' => 'Example']));
	}

	public function test_every_locale_has_the_complete_message_catalog() : void
	{
		$englishContents = file_get_contents(dirname(__DIR__, 2) . '/resources/lang/eng.json');
		self::assertIsString($englishContents);
		$englishCatalog = json_decode($englishContents, true, \AxolotlBucketLabs\DevTools\Support\JsonLimits::MAX_NESTING_DEPTH, JSON_THROW_ON_ERROR);
		self::assertIsArray($englishCatalog);
		$expectedKeys = array_keys($englishCatalog);
		sort($expectedKeys);

		foreach (Translator::supportedLocales() as $locale) {
			$contents = file_get_contents(dirname(__DIR__, 2) . '/resources/lang/' . $locale . '.json');
			self::assertIsString($contents);
			$catalog = json_decode($contents, true, \AxolotlBucketLabs\DevTools\Support\JsonLimits::MAX_NESTING_DEPTH, JSON_THROW_ON_ERROR);
			self::assertIsArray($catalog);
			$actualKeys = array_keys($catalog);
			sort($actualKeys);

			self::assertSame($expectedKeys, $actualKeys, "{$locale} must contain the complete message catalog");
			$translator = Translator::fromResources($locale);
			foreach ($expectedKeys as $key) {
				$localizedTemplate = $catalog[$key];
				$englishTemplate = $englishCatalog[$key];
				self::assertIsString($localizedTemplate);
				self::assertIsString($englishTemplate);
				self::assertSame($localizedTemplate, $translator->translate($key), "{$locale} unexpectedly used fallback for {$key}");
				preg_match_all('/\{[a-z]+\}/', $localizedTemplate, $actualPlaceholders);
				preg_match_all('/\{[a-z]+\}/', $englishTemplate, $expectedPlaceholders);
				sort($actualPlaceholders[0]);
				sort($expectedPlaceholders[0]);
				self::assertSame($expectedPlaceholders[0], $actualPlaceholders[0], "{$locale} has different placeholders for {$key}");
			}
		}
	}

	public function test_invalid_locale_is_rejected() : void
	{
		$this->expectException(InvalidArgumentException::class);
		Translator::fromResources('xx');
	}

	public function test_shared_virion_messages_are_translated_with_matching_parameters_in_every_locale() : void
	{
		$parameters = [
			'name' => 'ExampleVirion', 'package' => 'example/library', 'project' => 'C:/projects/ExamplePlugin',
			'path' => 'C:/virions/ExampleVirion', 'field' => 'require', 'first' => 'example/first',
			'second' => 'example/second', 'version' => '1.2.3', 'antigen' => 'Example\\Library',
			'versions' => '1.0.0, 1.1.0', 'error' => 'Technical detail', 'detail' => 'Technical detail',
			'requirements' => '^1.0.0 from ExamplePlugin', 'loaded' => '2.0.0', 'code' => 'example.issue',
			'message' => 'Technical validation detail', 'suggestion' => 'Correct the project input.',
		];
		$english = Translator::fromResources('eng');
		$keys = [
			TranslationKeys::VIRIONS_LOCAL_SKIPPED_PREPARED, TranslationKeys::VIRIONS_LOCAL_AMBIGUOUS_PACKAGE, TranslationKeys::VIRIONS_LOCAL_UNVERIFIED_NAME,
			TranslationKeys::VIRIONS_LOCAL_NAME_CONFLICT, TranslationKeys::VIRIONS_LOCAL_SELECTED_DEBUG, TranslationKeys::VIRIONS_LOCAL_METADATA_MISMATCH,
			TranslationKeys::VIRIONS_LOCAL_LAYOUT_MISMATCH,
			TranslationKeys::VIRIONS_LOADED, TranslationKeys::VIRIONS_SKIPPED_ONE, TranslationKeys::VIRIONS_SKIPPED_MANY,
			TranslationKeys::VIRIONS_STATUS_LOADED, TranslationKeys::VIRIONS_STATUS_SKIPPED, TranslationKeys::VIRIONS_STATUS_FAILED, TranslationKeys::VIRIONS_STATUS_CONFLICT,
			TranslationKeys::RUNTIME_PLUGIN_LOAD_FAILED, TranslationKeys::RUNTIME_PLUGIN_MANIFEST_FAILED, TranslationKeys::RUNTIME_SHARED_REQUIREMENTS_FAILED, TranslationKeys::RUNTIME_DUPLICATE_SOURCES,
			TranslationKeys::VIRIONS_LOAD_FAILED_DETAIL, TranslationKeys::VIRIONS_CONFLICT_DETAIL, TranslationKeys::VIRIONS_SKIPPED_DETAIL,
			TranslationKeys::VIRIONS_REQUIREMENT_MISSING, TranslationKeys::VIRIONS_REQUIREMENT_VERSION_MISMATCH,
			TranslationKeys::STATUS_ASYNC_AVAILABLE, TranslationKeys::STATUS_ASYNC_NOT_NEEDED, TranslationKeys::STATUS_ASYNC_UNAVAILABLE,
			TranslationKeys::STATUS_JOB_IDLE, TranslationKeys::STATUS_JOB_RUNNING, TranslationKeys::WORKER_UNEXPECTED_FAILURE,
			TranslationKeys::VALIDATION_ERROR, TranslationKeys::VALIDATION_WARNING, TranslationKeys::VALIDATION_NOTICE,
			TranslationKeys::VALIDATION_RELATED_FILE, TranslationKeys::VALIDATION_SUGGESTION,
		];
		foreach (Translator::supportedLocales() as $locale) {
			$contents = file_get_contents(dirname(__DIR__, 2) . '/resources/lang/' . $locale . '.json');
			self::assertIsString($contents);
			$catalog = json_decode($contents, true, \AxolotlBucketLabs\DevTools\Support\JsonLimits::MAX_NESTING_DEPTH, JSON_THROW_ON_ERROR);
			self::assertIsArray($catalog);
			$translator = Translator::fromResources($locale);
			foreach ($keys as $key) {
				self::assertArrayHasKey($key, $catalog, "{$locale} lacks {$key}");
				$template = $translator->translate($key);
				preg_match_all('/\{[a-z]+\}/', $template, $actual);
				preg_match_all('/\{[a-z]+\}/', $english->translate($key), $expected);
				sort($actual[0]);
				sort($expected[0]);
				self::assertSame($expected[0], $actual[0], "{$locale} {$key}");
				self::assertNotSame($key, $template);
				self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $translator->translate($key, $parameters));
			}
		}
	}
}
