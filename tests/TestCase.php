<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\NamespaceShader;
use AxolotlBucketLabs\DevTools\Build\PharBuilder;
use AxolotlBucketLabs\DevTools\Build\VirionResolver;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Validation\PluginProjectValidator;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected string $temporaryDirectory;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devtools-tests-' . bin2hex(random_bytes(8));
        $this->filesystem->ensureDirectory($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        $this->filesystem->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    protected function copyFixture(string $name, string $destinationName = ''): string
    {
        $source = __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . $name;
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . ($destinationName === '' ? $name : $destinationName);
        $this->filesystem->copyTree($source, $destination);

        return $destination;
    }

    protected function createSymbolicLink(string $target, string $link): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $created = symlink($target, $link);
        } finally {
            restore_error_handler();
        }
        if (!$created) {
            self::markTestSkipped('Creating symlinks is not permitted on this system.');
        }
    }

    /** @return array{PluginManifestReader, PluginProjectValidator} */
    protected function validationServices(): array
    {
        $reader = new PluginManifestReader(new YamlReader());

        return [$reader, new PluginProjectValidator($reader, new PhpSourceInspector())];
    }

    protected function builder(): PharBuilder
    {
        $yaml = new YamlReader();
        $inspector = new PhpSourceInspector();
        $reader = new PluginManifestReader($yaml);
        $discovery = new VirionDiscovery($this->filesystem);
        $factory = new VirionProjectFactory(new VirionManifestReader($yaml));

        return new PharBuilder(
            $this->filesystem,
            new PluginProjectValidator($reader, $inspector),
            $reader,
            new BuildConfigResolver($yaml),
            new VirionResolver($discovery, $factory, new VirionProjectSelector()),
            new VirionClassScanner($inspector),
            new NamespaceShader(),
            $inspector,
        );
    }
}
