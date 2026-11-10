<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use JsonException;
use function file_get_contents;
use function hash_file;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;

final class PreparedVirionProvenance
{
	public function __construct(private readonly VirionSourceFingerprint $sourceFingerprint = new VirionSourceFingerprint()) {}

	/** @return array<array-key, mixed> */
	public function verify(VirionProject $project, ?VirionRequirement $requirement = null) : array
	{
		$name = $requirement->name ?? $project->manifest->name;
		$expectedPackageSha256 = $requirement?->composerPackageSha256;
		$packageMismatch = "Prepared virion {$name} does not match this composer.lock. Run prepare into a new directory and use that --virions directory.";
		try {
			$provenance = $this->read($project->packageRoot . '/devtools-provenance.json');
		} catch (VirionException $error) {
			if ($expectedPackageSha256 === null) {
				throw $error;
			}
			throw new VirionException($packageMismatch, 0, $error);
		}
		if ($expectedPackageSha256 !== null && ($provenance['composerPackageSha256'] ?? null) !== $expectedPackageSha256) {
			throw new VirionException($packageMismatch);
		}
		if ($expectedPackageSha256 === null) {
			foreach ($provenance as $key => $entry) {
				if (!is_string($key)) {
					throw new VirionException("Expected named JSON object keys in {$project->packageRoot}/devtools-provenance.json.");
				}
			}
		}
		$manifestHash = hash_file('sha256', $project->manifest->path);
		if ($manifestHash === false || ($provenance['manifestSha256'] ?? null) !== $manifestHash) {
			throw new VirionException("Prepared manifest for {$name} changed or lacks verification metadata. Run prepare into a new directory from the reviewed installed package.");
		}
		if (($provenance['sourceSha256'] ?? null) !== $this->sourceFingerprint->hash($project->sourceRoot)) {
			throw new VirionException("Prepared source for {$name} changed after preparation. Run prepare into a new directory from the reviewed installed package.");
		}
		return $provenance;
	}

	/** @return array<array-key, mixed> */
	private function read(string $path) : array
	{
		if (!is_file($path) || Filesystem::isLink($path)) {
			throw new VirionException("Missing or unsafe {$path}. Run composer install --no-dev --no-scripts --no-plugins explicitly before prepare.");
		}
		$contents = file_get_contents($path);
		if ($contents === false) {
			throw new VirionException("Cannot read {$path}.");
		}
		try {
			$value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new VirionException("Cannot parse {$path}: {$error->getMessage()}", 0, $error);
		}
		if (!is_array($value)) {
			throw new VirionException("Expected a JSON object in {$path}.");
		}
		return $value;
	}
}
