<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\PreparedVirionProvenance;
use AxolotlBucketLabs\DevTools\Virion\VirionDependencyResolver;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use AxolotlBucketLabs\DevTools\Virion\VirionSourceFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use function file_put_contents;
use function hash_file;
use function json_encode;
use function rename;
use function str_repeat;
use function unlink;
use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;

final class PreparedVirionProvenanceTest extends TestCase
{
	public function test_verified_metadata_and_pinned_resolution_preserve_the_package() : void
	{
		[$project, $metadata] = $this->preparedProject();
		$requirement = new VirionRequirement('sharedvirion', '*', 'composer.lock', $metadata['composerPackageSha256']);

		self::assertSame($metadata, (new PreparedVirionProvenance())->verify($project, $requirement));
		self::assertSame([$project], (new VirionDependencyResolver(new VirionProjectSelector()))->resolve(['SharedVirion' => [$project]], [$requirement]));
	}

	public function test_content_verification_does_not_require_a_package_fingerprint_when_unpinned() : void
	{
		[$project, $metadata] = $this->preparedProject();
		unset($metadata['composerPackageSha256']);
		$this->writeMetadata($project, $metadata);

		self::assertSame($metadata, (new PreparedVirionProvenance())->verify($project));
	}

	public function test_pinned_resolution_preserves_tolerance_of_unrelated_numeric_metadata_keys() : void
	{
		[$project, $metadata] = $this->preparedProject();
		$metadata[0] = 'unrelated metadata';
		$this->writeMetadata($project, $metadata);
		$requirement = new VirionRequirement('sharedvirion', '*', 'composer.lock', $metadata['composerPackageSha256']);

		self::assertSame([$project], (new VirionDependencyResolver(new VirionProjectSelector()))->resolve(['SharedVirion' => [$project]], [$requirement]));
	}

	#[DataProvider('invalidProvenance')]
	public function test_runtime_resolution_requires_valid_provenance_only_for_pinned_packages(?string $contents, bool $pinned) : void
	{
		[$project, $metadata] = $this->preparedProject();
		$path = $project->packageRoot . '/devtools-provenance.json';
		if ($contents === null) {
			unlink($path);
		} else {
			file_put_contents($path, $contents);
		}
		$requirement = new VirionRequirement('sharedvirion', '*', 'composer.lock', $pinned ? $metadata['composerPackageSha256'] : null);
		if ($pinned) {
			$this->expectException(VirionException::class);
			$this->expectExceptionMessage('Prepared virion sharedvirion does not match this composer.lock. Run prepare into a new directory and use that --virions directory.');
		}
		$resolved = (new VirionDependencyResolver(new VirionProjectSelector()))->resolve(['SharedVirion' => [$project]], [$requirement]);
		self::assertSame([$project], $resolved);
	}

	/** @return iterable<string, array{?string, bool}> */
	public static function invalidProvenance() : iterable
	{
		foreach (['missing' => null, 'malformed JSON' => '{', 'scalar' => 'true', 'list' => '["metadata"]', 'missing hashes' => '{}'] as $name => $contents) {
			yield $name . ' pinned' => [$contents, true];
			yield $name . ' unpinned' => [$contents, false];
		}
	}

	public function test_package_fingerprint_is_checked_before_manifest_and_source() : void
	{
		[$project] = $this->preparedProject();
		$this->writeMetadata($project, ['composerPackageSha256' => 'wrong']);
		$requirement = new VirionRequirement('sharedvirion', '*', 'composer.lock', str_repeat('a', 64));

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Prepared virion sharedvirion does not match this composer.lock.');
		(new PreparedVirionProvenance())->verify($project, $requirement);
	}

	public function test_manifest_is_checked_before_changed_source() : void
	{
		[$project] = $this->preparedProject();
		file_put_contents($project->manifest->path, "\n# changed manifest\n", FILE_APPEND);
		file_put_contents($project->sourceRoot . '/changed.txt', 'changed source');

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Prepared manifest for SharedVirion changed or lacks verification metadata. Run prepare into a new directory from the reviewed installed package.');
		(new PreparedVirionProvenance())->verify($project);
	}

	public function test_changed_source_resources_are_rejected_after_valid_manifest() : void
	{
		[$project] = $this->preparedProject();
		file_put_contents($project->sourceRoot . '/resources/default.txt', 'changed resource');

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Prepared source for SharedVirion changed after preparation. Run prepare into a new directory from the reviewed installed package.');
		(new PreparedVirionProvenance())->verify($project);
	}

	public function test_missing_manifest_hash_fails_before_source_verification() : void
	{
		[$project, $metadata] = $this->preparedProject();
		unset($metadata['manifestSha256'], $metadata['sourceSha256']);
		$this->writeMetadata($project, $metadata);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Prepared manifest for SharedVirion changed or lacks verification metadata.');
		(new PreparedVirionProvenance())->verify($project);
	}

	public function test_missing_source_hash_is_rejected() : void
	{
		[$project, $metadata] = $this->preparedProject();
		unset($metadata['sourceSha256']);
		$this->writeMetadata($project, $metadata);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Prepared source for SharedVirion changed after preparation.');
		(new PreparedVirionProvenance())->verify($project);
	}

	#[DataProvider('invalidMetadata')]
	public function test_unpinned_verification_preserves_json_diagnostics(string $contents, string $message) : void
	{
		[$project] = $this->preparedProject();
		$path = $project->packageRoot . '/devtools-provenance.json';
		file_put_contents($path, $contents);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage($message . $path);
		(new PreparedVirionProvenance())->verify($project);
	}

	/** @return iterable<string, array{string, string}> */
	public static function invalidMetadata() : iterable
	{
		yield 'malformed JSON' => ['{', 'Cannot parse '];
		yield 'scalar' => ['true', 'Expected a JSON object in '];
		yield 'list' => ['["metadata"]', 'Expected named JSON object keys in '];
	}

	public function test_symbolic_link_provenance_is_rejected() : void
	{
		[$project] = $this->preparedProject();
		$path = $project->packageRoot . '/devtools-provenance.json';
		$target = $this->temporaryDirectory . '/metadata.json';
		rename($path, $target);
		$this->createSymbolicLink($target, $path);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage("Missing or unsafe {$path}.");
		(new PreparedVirionProvenance())->verify($project);
	}

	public function test_symbolic_link_source_is_rejected_even_when_its_bytes_match() : void
	{
		[$project] = $this->preparedProject();
		$path = $project->sourceRoot . '/resources/default.txt';
		$target = $this->temporaryDirectory . '/default.txt';
		rename($path, $target);
		$this->createSymbolicLink($target, $path);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Refusing symbolic link in prepared source ' . $path . '.');
		(new PreparedVirionProvenance())->verify($project);
	}

	/** @return array{VirionProject, array<string, string>} */
	private function preparedProject() : array
	{
		$root = $this->copyFixture('SharedVirion');
		$project = (new VirionProjectFactory(new VirionManifestReader(new YamlReader())))->open($root);
		$manifestHash = hash_file('sha256', $project->manifest->path);
		self::assertIsString($manifestHash);
		$metadata = [
			'composerPackageSha256' => str_repeat('a', 64),
			'manifestSha256' => $manifestHash,
			'sourceSha256' => (new VirionSourceFingerprint())->hash($project->sourceRoot),
		];
		$this->writeMetadata($project, $metadata);
		return [$project, $metadata];
	}

	/** @param array<array-key, mixed> $metadata */
	private function writeMetadata(VirionProject $project, array $metadata) : void
	{
		file_put_contents($project->packageRoot . '/devtools-provenance.json', json_encode($metadata, JSON_THROW_ON_ERROR));
	}
}
