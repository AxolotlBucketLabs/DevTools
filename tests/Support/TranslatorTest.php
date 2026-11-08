<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Support;

use AxolotlBucketLabs\DevTools\Support\Translator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function test_all_axolotl_locales_have_a_catalog_and_a_translated_runtime_message(): void
    {
        $englishHelp = Translator::fromResources('eng')->translate('cli.help');
        foreach (Translator::supportedLocales() as $locale) {
            $translator = Translator::fromResources($locale);

            self::assertSame($locale, $translator->locale());
            self::assertNotSame('runtime.ready', $translator->translate('runtime.ready'));
            self::assertStringContainsString('DevTools', $translator->translate('cli.version', ['version' => '3.3.0']));
            if ($locale !== 'eng') {
                self::assertNotSame($englishHelp, $translator->translate('cli.help'));
            }
        }
    }

    public function test_locale_aliases_and_english_fallback_work(): void
    {
        self::assertSame('chs', Translator::fromResources('zh-CN')->locale());
        self::assertSame('vie', Translator::fromResources('vi')->locale());
        self::assertSame('unknown.key', Translator::fromResources('vie')->translate('unknown.key', ['value' => 'Example']));
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Translator::fromResources('xx');
    }

    public function test_shared_virion_messages_are_translated_with_matching_parameters_in_every_locale(): void
    {
        $parameters = [
            'name' => 'ExampleVirion', 'package' => 'example/library', 'project' => 'C:/projects/ExamplePlugin',
            'path' => 'C:/virions/ExampleVirion', 'field' => 'require', 'first' => 'example/first',
            'second' => 'example/second', 'version' => '1.2.3', 'antigen' => 'Example\\Library',
            'versions' => '1.0.0, 1.1.0', 'error' => 'Technical detail',
        ];
        $english = Translator::fromResources('eng');
        $keys = [
            'virions.local.skipped_prepared', 'virions.local.ambiguous_package', 'virions.local.unverified_name',
            'virions.local.name_conflict', 'virions.local.selected_debug', 'virions.local.metadata_mismatch',
            'virions.local.layout_mismatch', 'runtime.shared_requirements_failed',
            'virions.loaded', 'virions.skipped_one', 'virions.skipped_many',
            'virions.status.loaded', 'virions.status.skipped', 'virions.status.failed', 'virions.status.conflict',
        ];
        foreach (Translator::supportedLocales() as $locale) {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/resources/lang/' . $locale . '.json');
            self::assertIsString($contents);
            $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
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
                if ($locale !== 'eng') {
                    self::assertNotSame($english->translate($key), $template);
                }
                self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $translator->translate($key, $parameters));
            }
        }
    }
}
