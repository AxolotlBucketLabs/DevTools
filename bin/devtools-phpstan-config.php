<?php

declare(strict_types=1);

use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\PhpStanConfigGenerator;
use NhanAZ\DevTools\Build\VirionResolver;
use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionProjectSelector;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$scanPaths = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $matches) !== 1) {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
    if ($matches[1] === 'scan-path') {
        if ($matches[2] === '') {
            fwrite(STDERR, "Argument --scan-path may not be empty.\n");
            exit(2);
        }
        $scanPaths[] = $matches[2];
        continue;
    }
    if (!in_array($matches[1], ['project', 'server', 'virions', 'level', 'out', 'tmp-dir'], true)) {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
    $options[$matches[1]] = $matches[2];
}

foreach (['project', 'server', 'virions', 'level', 'out', 'tmp-dir'] as $required) {
    if (!isset($options[$required]) || $options[$required] === '') {
        fwrite(STDERR, "Missing required argument: --{$required}=<value>\n");
        exit(2);
    }
}

try {
    $filesystem = new Filesystem();
    $yaml = new YamlReader();
    $manifest = (new PluginManifestReader($yaml))->read(
        $options['project'] . DIRECTORY_SEPARATOR . 'plugin.yml',
    );
    $config = (new BuildConfigResolver($yaml))->resolve(
        $options['project'],
        $manifest->description->getName(),
    );
    $virions = (new VirionResolver(
        new VirionDiscovery($filesystem),
        new VirionProjectFactory(new VirionManifestReader($yaml)),
        new VirionProjectSelector(),
    ))->resolve($config->virions, $options['virions'], false);
    $contents = (new PhpStanConfigGenerator())->generate(
        $options['project'],
        $options['server'],
        $virions,
        $options['level'],
        $options['tmp-dir'],
        $scanPaths,
    );

    $outputDirectory = dirname($options['out']);
    $filesystem->ensureDirectory($outputDirectory);
    if (Filesystem::isLink($options['out'])) {
        throw new RuntimeException("Refusing symbolic-link PHPStan configuration output: {$options['out']}");
    }
    $written = file_put_contents($options['out'], $contents);
    if ($written === false || $written !== strlen($contents)) {
        throw new RuntimeException("Cannot write PHPStan configuration to {$options['out']}.");
    }
    fwrite(STDOUT, "Generated PHPStan configuration at {$options['out']}.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "PHPStan setup failed: {$error->getMessage()}\n");
    exit(1);
}
