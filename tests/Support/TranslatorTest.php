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
            self::assertStringContainsString('DevTools', $translator->translate('cli.version', ['version' => '3.1.0']));
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
}
