<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\LocalizedException;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;

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
		$plugin = $this->pluginManifestReader->read($project . '/' . ArtifactFile::PLUGIN_MANIFEST);
		$virion = $this->virionManifestReader->read($project . '/' . ArtifactFile::VIRION_MANIFEST);

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
		$pluginOutput = Path::join($outputDirectory, $pluginName . ArtifactFile::PHAR_EXTENSION);
		$virionOutput = Path::join($outputDirectory, $virionName . ArtifactFile::PHAR_EXTENSION);
		$this->installer->assertDestinations([$pluginOutput, $virionOutput], $overwrite);

		$temporaryDirectory = Path::join($outputDirectory, '.devtools-dual-' . RandomToken::pathSuffix());
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
