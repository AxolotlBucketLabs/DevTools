<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Loader;

use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginLoader;
use AxolotlBucketLabs\DevTools\Loader\PluginSourceConflictDetector;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use Phar;
use pocketmine\plugin\PluginDescriptionParseException;
use pocketmine\thread\ThreadSafeClassLoader;

final class FolderPluginLoaderTest extends TestCase
{
    public function test_valid_folder_is_discovered_and_registered(): void
    {
        $plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
        $this->filesystem->ensureDirectory($plugins);
        $project = $this->copyFixture('ValidPlugin', 'plugins/ValidPlugin');
        [$reader, $validator] = $this->validationServices();
        $classLoader = new ThreadSafeClassLoader();
        $loader = new FolderPluginLoader($classLoader, $reader, $validator);

        self::assertSame([str_replace('\\', '/', $project)], (new FolderPluginDiscovery())->discover($plugins));
        self::assertTrue($loader->canLoadPlugin($project));
        self::assertSame('FixturePlugin', $loader->getPluginDescription($project)?->getName());

        $loader->loadPlugin($project);

        self::assertSame(
            str_replace('/', DIRECTORY_SEPARATOR, $project . '/src/Main.php'),
            $classLoader->findClass('Fixture\Plugin\Main'),
        );
        self::assertFileExists($project . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config.yml');
    }

    public function test_configured_devtools_source_project_is_not_loadable(): void
    {
        $project = $this->copyFixture('ValidPlugin', 'plugins/DevTools');
        [$reader, $validator] = $this->validationServices();
        $loader = new FolderPluginLoader(new ThreadSafeClassLoader(), $reader, $validator, $project);

        self::assertFalse($loader->canLoadPlugin($project));
        self::assertNull($loader->getPluginDescription($project));
    }

    public function test_invalid_folder_is_not_silently_ignored(): void
    {
        $project = $this->copyFixture('MissingMain');
        [$reader, $validator] = $this->validationServices();
        $loader = new FolderPluginLoader(new ThreadSafeClassLoader(), $reader, $validator);

        self::assertTrue($loader->canLoadPlugin($project));
        $this->expectException(PluginDescriptionParseException::class);
        $this->expectExceptionMessage('src/Main.php');

        $loader->getPluginDescription($project);
    }

    public function test_project_with_invalid_devtools_configuration_is_not_loadable(): void
    {
        $project = $this->copyFixture('ValidPlugin', 'plugins/InvalidConfiguration');
        file_put_contents($project . '/devtools.yml', "include-paths: LICENSE\n");
        [$reader, $validator] = $this->validationServices();
        $loader = new FolderPluginLoader(
            new ThreadSafeClassLoader(),
            $reader,
            $validator,
            null,
            null,
            static fn(string $root): ?string => $root === '' ? null : 'include-paths must be a list',
        );

        $this->expectException(PluginDescriptionParseException::class);
        $this->expectExceptionMessage('DevTools configuration is invalid');

        $loader->getPluginDescription($project);
    }

    public function test_hidden_and_build_directories_are_skipped(): void
    {
        $plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
        $this->filesystem->ensureDirectory($plugins . DIRECTORY_SEPARATOR . '.hidden' . DIRECTORY_SEPARATOR . 'src');
        $this->filesystem->ensureDirectory($plugins . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'src');

        self::assertSame([], (new FolderPluginDiscovery())->discover($plugins));
    }

    public function test_source_folder_without_manifest_is_reported(): void
    {
        $project = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'IncompletePlugin';
        $this->filesystem->ensureDirectory($project . DIRECTORY_SEPARATOR . 'src');
        [$reader, $validator] = $this->validationServices();
        $loader = new FolderPluginLoader(new ThreadSafeClassLoader(), $reader, $validator);

        self::assertTrue($loader->canLoadPlugin($project));
        $this->expectException(PluginDescriptionParseException::class);
        $this->expectExceptionMessage('plugin.yml is missing');

        $loader->getPluginDescription($project);
    }

    public function test_folder_and_phar_with_the_same_plugin_name_are_reported(): void
    {
        $plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
        $this->filesystem->ensureDirectory($plugins);
        $this->copyFixture('ValidPlugin', 'plugins/ValidPlugin');
        $source = $this->copyFixture('ValidPlugin', 'source/ValidPlugin');
        $path = $plugins . DIRECTORY_SEPARATOR . 'ValidPlugin.phar';
        $phar = new Phar($path);
        $phar->buildFromDirectory($source);
        $phar->setStub("<?php __HALT_COMPILER();\n");
        unset($phar);
        [$reader] = $this->validationServices();

        $warnings = (new PluginSourceConflictDetector($reader))->detect($plugins);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('development folder', $warnings[0]);
        self::assertStringContainsString('PHAR', $warnings[0]);
    }

    public function test_plugin_cannot_shadow_a_loaded_shared_virion(): void
    {
        $project = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'CollisionPlugin';
        $this->filesystem->ensureDirectory($project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion');
        file_put_contents(
            $project . DIRECTORY_SEPARATOR . 'plugin.yml',
            "name: CollisionPlugin\nversion: 1.0.0\nmain: Main\napi: 5.0.0\n",
        );
        file_put_contents(
            $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php',
            "<?php\nfinal class Main extends \\pocketmine\\plugin\\PluginBase {}\n",
        );
        file_put_contents(
            $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Value.inc',
            "<?php\nnamespace Shared\\Virion; final class Value {}\n",
        );
        [$reader, $validator] = $this->validationServices();
        $detector = new PluginSourceConflictDetector($reader);
        $loader = new FolderPluginLoader(
            new ThreadSafeClassLoader(),
            $reader,
            $validator,
            null,
            static function (string $root) use ($detector): array {
                return $detector->detectVirionConflicts($root, ['Shared\\Virion'], ['Shared\\Virion\\Value']);
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shadows a loaded shared virion');

        $loader->loadPlugin($project);
    }
}
