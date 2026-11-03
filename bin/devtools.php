<?php

declare(strict_types=1);

use NhanAZ\DevTools\Build\ArtifactInspector;
use NhanAZ\DevTools\Build\ArtifactSetInstaller;
use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\ComposerVirionPreparer;
use NhanAZ\DevTools\Build\DualArtifactBuilder;
use NhanAZ\DevTools\Build\NamespaceShader;
use NhanAZ\DevTools\Build\PharBuilder;
use NhanAZ\DevTools\Build\PharExtractor;
use NhanAZ\DevTools\Build\ProjectRoleDetector;
use NhanAZ\DevTools\Build\VirionBuilder;
use NhanAZ\DevTools\Build\VirionResolver;
use NhanAZ\DevTools\Build\ZipArchiveWriter;
use NhanAZ\DevTools\Cli\Application;
use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\PhpSourceInspector;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Validation\PluginProjectValidator;
use NhanAZ\DevTools\Virion\VirionClassScanner;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionProjectSelector;

ini_set('display_errors', 'stderr');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
try {
    if (!is_file($autoload)) {
        throw new RuntimeException('DevTools dependencies are missing. Run composer install --no-scripts --no-plugins including development dependencies for Axolotl-PM manifest types.');
    }
    require $autoload;

    $filesystem = new Filesystem();
    $yaml = new YamlReader();
    $inspector = new PhpSourceInspector();
    $reader = new PluginManifestReader($yaml);
    $validator = new PluginProjectValidator($reader, $inspector);
    $config = new BuildConfigResolver($yaml);
    $virionManifestReader = new VirionManifestReader($yaml);
    $virionFactory = new VirionProjectFactory($virionManifestReader);
    $virionDiscovery = new VirionDiscovery($filesystem);
    $resolver = new VirionResolver($virionDiscovery, $virionFactory, new VirionProjectSelector());
    $pluginBuilder = new PharBuilder($filesystem, $validator, $reader, $config, $resolver, new VirionClassScanner($inspector), new NamespaceShader(), $inspector);
    $virionBuilder = new VirionBuilder($filesystem, $virionManifestReader, $virionFactory, new VirionClassScanner($inspector));
    $version = $reader->read(dirname(__DIR__) . '/plugin.yml')->description->getVersion();
    $application = new Application(
        $version,
        $reader,
        $validator,
        $config,
        $resolver,
        $pluginBuilder,
        $virionBuilder,
        new PharExtractor($filesystem),
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
