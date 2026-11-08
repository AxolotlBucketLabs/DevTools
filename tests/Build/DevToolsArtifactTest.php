<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use Phar;

final class DevToolsArtifactTest extends TestCase
{
    public function test_release_gate_accepts_parser_only_and_rejects_bundled_development_dependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $result = $this->builder()->build($root, $this->temporaryDirectory . '/virions', $this->temporaryDirectory);
        [$exit, $output] = $this->validate($result->outputPath);
        self::assertSame(0, $exit, $output);

        $phar = new Phar($result->outputPath);
        foreach (Translator::supportedLocales() as $locale) {
            self::assertTrue(isset($phar["resources/lang/{$locale}.json"]));
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

    /** @return array{int, string} */
    private function validate(string $path): array
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
