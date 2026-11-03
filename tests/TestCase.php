<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests;

use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\NamespaceShader;
use NhanAZ\DevTools\Build\PharBuilder;
use NhanAZ\DevTools\Build\VirionResolver;
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
