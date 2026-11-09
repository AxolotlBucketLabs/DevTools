<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\LocalizedException;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use function bin2hex;
use function random_bytes;

final class DualArtifactBuilder
{
	public function __construct(
		private readonly Filesystem $filesystem,
		private readonly PharBuilder $pluginBuilder,
		private readonly VirionBuilder $virionBuilder,
		private readonly ArtifactSetInstaller $installer,
		private readonly PluginManifestReader $pluginManifestReader,
		private readonly VirionManifestReader $virionManifestReader,
		private readonly ProjectRoleDetector $roleDetector,
	) {}

	public function prepare(string $project) : DualArtifactProject
	{
		if ($this->roleDetector->detect($project) !== ProjectRole::DUAL) {
			throw new LocalizedException('build.both_required', ['project' => $project]);
		}
		$plugin = $this->pluginManifestReader->read($project . '/plugin.yml');
		$virion = $this->virionManifestReader->read($project . '/virion.yml');

		return new DualArtifactProject($project, $plugin->description->getName(), $virion->name);
	}

	/** @return array{plugin: string, virion: string} */
	public function build(
		DualArtifactProject $project,
		string $virionsDirectory,
		string $outputDirectory,
		bool $overwrite,
	) : array {
		$pluginName = $project->pluginName . '-plugin';
		$virionName = $project->virionName . '-virion';
		$pluginOutput = Path::join($outputDirectory, $pluginName . '.phar');
		$virionOutput = Path::join($outputDirectory, $virionName . '.phar');
		$this->installer->assertDestinations([$pluginOutput, $virionOutput], $overwrite);

		$temporaryDirectory = Path::join($outputDirectory, '.devtools-dual-' . bin2hex(random_bytes(8)));
		$this->filesystem->ensureDirectory($temporaryDirectory);
		try {
			$plugin = $this->pluginBuilder->build($project->root, $virionsDirectory, $temporaryDirectory, false, $pluginName);
			$virion = $this->virionBuilder->build($project->root, $temporaryDirectory, false, $virionName);
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
