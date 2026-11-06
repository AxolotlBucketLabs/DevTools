<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;

use function bin2hex;
use function random_bytes;

final class DualArtifactBuilder
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly PharBuilder $pluginBuilder,
        private readonly VirionBuilder $virionBuilder,
        private readonly ArtifactSetInstaller $installer,
    ) {}

    /** @return array{plugin: string, virion: string} */
    public function build(
        string $project,
        string $virionsDirectory,
        string $outputDirectory,
        bool $overwrite,
        string $pluginName,
        string $virionName,
    ): array {
        $pluginOutput = Path::join($outputDirectory, $pluginName . '.phar');
        $virionOutput = Path::join($outputDirectory, $virionName . '.phar');
        $this->installer->assertDestinations([$pluginOutput, $virionOutput], $overwrite);

        $temporaryDirectory = Path::join($outputDirectory, '.devtools-dual-' . bin2hex(random_bytes(8)));
        $this->filesystem->ensureDirectory($temporaryDirectory);
        try {
            $plugin = $this->pluginBuilder->build($project, $virionsDirectory, $temporaryDirectory, false, $pluginName);
            $virion = $this->virionBuilder->build($project, $temporaryDirectory, false, $virionName);
            $this->installer->install([
                ['temporary' => $plugin->outputPath, 'output' => $pluginOutput],
                ['temporary' => $virion->outputPath, 'output' => $virionOutput],
            ], $overwrite);
        } finally {
            $this->filesystem->removeTree($temporaryDirectory);
        }

        return ['plugin' => $pluginOutput, 'virion' => $virionOutput];
    }
}
