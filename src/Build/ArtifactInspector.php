<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\HashAlgorithm;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use Phar;
use Throwable;
use function count;
use function hash_file;
use function is_file;
use function realpath;
use function str_replace;

final class ArtifactInspector
{
	public function __construct(
		private readonly PluginManifestReader $manifestReader,
		private readonly ?VirionManifestReader $virionManifestReader = null,
	) {}

	public static function roleMatches(string $detected, string $requested) : bool
	{
		return $detected === $requested || $detected === ProjectRole::DUAL->value;
	}

	/** @return array<string, mixed> */
	public function inspect(string $path) : array
	{
		$real = realpath($path);
		if ($real === false || !is_file($real)) {
			throw new BuildException("Artifact does not exist: {$path}");
		}
		$snapshot = PharSnapshot::create($real);
		try {
			return $this->inspectSnapshot($real, $snapshot, $path);
		} finally {
			PharSnapshot::remove($snapshot);
		}
	}

	/** @return array<string, mixed> */
	private function inspectSnapshot(string $real, string $snapshot, string $displayPath) : array
	{
		try {
			$phar = new Phar($snapshot);
		} catch (Throwable $error) {
			throw new BuildException("Cannot open PHAR {$displayPath}: {$error->getMessage()}", 0, $error);
		}
		$prefix = 'phar://' . str_replace('\\', '/', $snapshot) . '/';
		$hasPlugin = isset($phar[ArtifactFile::PLUGIN_MANIFEST]);
		$hasVirion = isset($phar[ArtifactFile::VIRION_MANIFEST]);
		if (!$hasPlugin && !$hasVirion) {
			throw new BuildException("Artifact is missing plugin.yml and virion.yml: {$displayPath}");
		}
		if ($hasVirion && $this->virionManifestReader === null) {
			throw new BuildException('Virion artifact inspection is not configured.');
		}
		$type = $hasPlugin && $hasVirion ? ProjectRole::DUAL->value : ($hasPlugin ? ProjectRole::PLUGIN->value : ProjectRole::VIRION->value);
		$data = [
			'artifact' => $real,
			'type' => $type,
			'files' => count($phar),
		];
		if ($hasPlugin) {
			$manifest = $this->manifestReader->read($prefix . ArtifactFile::PLUGIN_MANIFEST);
			$main = $manifest->expectedMainRelativePath();
			if ($main === null || !isset($phar[ArtifactFile::SOURCE_DIRECTORY . '/' . $main])) {
				throw new BuildException("Artifact is missing its declared main class source: {$displayPath}");
			}
			$data['plugin'] = ['name' => $manifest->description->getName(), 'version' => $manifest->description->getVersion()];
		}
		if ($hasVirion) {
			$manifest = $this->virionManifestReader->read($prefix . ArtifactFile::VIRION_MANIFEST);
			if (!isset($phar[ArtifactFile::SOURCE_DIRECTORY])) {
				throw new BuildException("Artifact is missing its virion source directory: {$displayPath}");
			}
			$data['virion'] = ['name' => $manifest->name, 'version' => $manifest->version, 'antigen' => $manifest->antigen];
		}
		/** @var array{hash: string, hash_type: string}|false $signature */
		$signature = $phar->getSignature();
		if ($signature === false || $signature['hash_type'] === '') {
			throw new BuildException("Artifact has no PHAR signature: {$displayPath}");
		}
		$hash = hash_file(HashAlgorithm::SHA256, $real);
		if ($hash === false) {
			throw new BuildException("Cannot hash artifact: {$displayPath}");
		}
		unset($phar);

		return $data + ['sha256' => $hash, 'signature' => $signature['hash_type']];
	}

}
