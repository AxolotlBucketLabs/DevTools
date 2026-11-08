<?php

declare(strict_types=1);

use AxolotlBucketLabs\DevTools\Build\ArtifactInspector;
use AxolotlBucketLabs\DevTools\Build\ArtifactSetInstaller;
use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\ComposerVirionPreparer;
use AxolotlBucketLabs\DevTools\Build\DualArtifactBuilder;
use AxolotlBucketLabs\DevTools\Build\NamespaceShader;
use AxolotlBucketLabs\DevTools\Build\PharBuilder;
use AxolotlBucketLabs\DevTools\Build\PharExtractor;
use AxolotlBucketLabs\DevTools\Build\ProjectRoleDetector;
use AxolotlBucketLabs\DevTools\Build\VirionBuilder;
use AxolotlBucketLabs\DevTools\Build\VirionResolver;
use AxolotlBucketLabs\DevTools\Build\ZipArchiveWriter;
use AxolotlBucketLabs\DevTools\Cli\Application;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Validation\PluginProjectValidator;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;

ini_set('display_errors', 'stderr');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
try {
    if (!is_file($autoload)) {
        throw new RuntimeException('DevTools dependencies are missing. Run composer install --no-scripts --no-plugins including development dependencies for Axolotl-PM manifest types.');
    }
    require $autoload;

    $debug = new DebugLogger(in_array('--debug', array_slice($argv ?? [], 1), true)
        ? static function (string $message): void {
            fwrite(STDERR, '[debug] ' . $message . "\n");
        }
        : null);
    $filesystem = new Filesystem();
    $yaml = new YamlReader();
    $inspector = new PhpSourceInspector();
    $reader = new PluginManifestReader($yaml);
    $validator = new PluginProjectValidator($reader, $inspector);
    $config = new BuildConfigResolver($yaml);
    $virionManifestReader = new VirionManifestReader($yaml);
    $virionFactory = new VirionProjectFactory($virionManifestReader);
    $virionDiscovery = new VirionDiscovery($filesystem);
    $resolver = new VirionResolver($virionDiscovery, $virionFactory, new VirionProjectSelector(), $debug);
    $pluginBuilder = new PharBuilder($filesystem, $validator, $reader, $config, $resolver, new VirionClassScanner($inspector), new NamespaceShader(), $inspector, $debug);
    $virionBuilder = new VirionBuilder($filesystem, $virionManifestReader, $virionFactory, new VirionClassScanner($inspector), $debug);
    $version = $reader->read(dirname(__DIR__) . '/plugin.yml')->description->getVersion();
    $application = new Application(
        $version,
        $reader,
        $validator,
        $config,
        $resolver,
        $pluginBuilder,
        $virionBuilder,
        new PharExtractor($filesystem, $debug),
        new ZipArchiveWriter($filesystem),
        new ArtifactInspector($reader, $virionManifestReader),
        new ComposerVirionPreparer($filesystem),
        $virionManifestReader,
        new ProjectRoleDetector(),
        new DualArtifactBuilder(
            $filesystem,
            $pluginBuilder,
            $virionBuilder,
            new ArtifactSetInstaller(),
        ),
        $debug,
        Translator::fromResources(),
    );
    exit($application->run(array_slice($argv ?? [], 1)));
} catch (Throwable $error) {
    $message = $error->getMessage();
    if (in_array('--json', $argv ?? [], true)) {
        fwrite(STDOUT, json_encode([
            'schema_version' => 1, 'success' => false, 'command' => 'bootstrap',
            'tool' => ['name' => 'DevTools', 'version' => null], 'data' => (object) [],
            'diagnostics' => [['code' => 'cli.environment', 'severity' => 'error', 'message' => $message, 'file' => null, 'line' => null, 'suggestion' => 'Check PHP extensions and install the locked DevTools dependencies (including development packages), then retry.']],
            'checks' => ['structure' => 'not_run', 'dependencies' => 'not_run', 'artifact' => 'not_run', 'static_analysis' => 'not_run', 'runtime' => 'not_run'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    } else {
        fwrite(STDERR, '[cli.environment] ' . $message . "\n");
    }
    exit(1);
}
