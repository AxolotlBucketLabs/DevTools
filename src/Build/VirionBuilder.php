<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\HashAlgorithm;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Virion\PreparedVirionProvenance;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use FilesystemIterator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use function count;
use function hash;
use function hash_equals;
use function hash_file;
use function implode;
use function ini_get;
use function is_dir;
use function is_file;
use function preg_match;
use function realpath;
use function str_replace;
use function str_starts_with;
use const DIRECTORY_SEPARATOR;

final class VirionBuilder
{
	/** @var list<string> */
	private const NOTICE_FILES = ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'COPYING', 'COPYING.md', 'NOTICE', 'NOTICE.md', 'THIRD_PARTY_NOTICES.md'];

	public function __construct(
		private readonly Filesystem $filesystem,
		private readonly VirionManifestReader $manifestReader,
		private readonly VirionProjectFactory $projectFactory,
		private readonly VirionClassScanner $classScanner,
		private readonly ?DebugLogger $debug = null,
		private readonly ArtifactSetInstaller $installer = new ArtifactSetInstaller(),
		private readonly PreparedVirionProvenance $provenance = new PreparedVirionProvenance(),
		private readonly PharArchiveWriter $archiveWriter = new PharArchiveWriter(),
	) {}

	public function build(
		string $projectRoot,
		string $outputDirectory,
		bool $overwrite = false,
		?string $outputName = null,
	) : VirionBuildResult {
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_BUILD_VIRION_STARTED, ['project' => $projectRoot]);
		if ((string) ini_get('phar.readonly') !== '0') {
			throw new BuildException('Cannot build a PHAR because phar.readonly is enabled. Start PHP with -d phar.readonly=0.');
		}
		$project = $this->projectFactory->open($projectRoot);
		if ($project->phar) {
			throw new BuildException("Cannot build a virion from a PHAR. Use the source folder containing virion.yml: {$projectRoot}");
		}
		$classes = $this->classScanner->scan($project);
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_BUILD_VIRION_CLASSES_SCANNED, ['count' => count($classes), 'name' => $project->manifest->name]);
		if ($classes === []) {
			throw new BuildException("Cannot build virion {$project->manifest->name}: no PHP classes were found.");
		}
		foreach ($classes as $class) {
			if (!str_starts_with($class, $project->manifest->antigen . '\\')) {
				throw new BuildException(
					"Cannot build virion {$project->manifest->name}: class {$class} is outside antigen {$project->manifest->antigen}.",
				);
			}
		}

		if (Filesystem::isLink($outputDirectory)) {
			throw new BuildException("Refusing symbolic-link build output directory: {$outputDirectory}");
		}
		$this->filesystem->ensureDirectory($outputDirectory);
		$realProject = realpath($projectRoot);
		$realOutput = realpath($outputDirectory);
		if ($realProject === false || $realOutput === false) {
			throw new BuildException('Cannot resolve the virion project or build output directory.');
		}
		if ($realOutput === $realProject
			|| Path::isInside($realProject . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY, $realOutput)
			|| Path::isInside($realProject . DIRECTORY_SEPARATOR . 'resources', $realOutput)) {
			throw new BuildException("Refusing build output inside virion source or resources: {$realOutput}.");
		}

		$safeName = $outputName ?? $project->manifest->name;
		if (preg_match('/^[A-Za-z0-9_.-]+$/D', $safeName) !== 1) {
			throw new BuildException("Cannot derive a safe virion artifact name from {$safeName}.");
		}
		$outputPath = Path::join($outputDirectory, $safeName . ArtifactFile::PHAR_EXTENSION);
		$this->installer->assertDestinations([$outputPath], $overwrite);

		$nonce = RandomToken::pathSuffix();
		$stage = Path::join($outputDirectory, '.devtools-virion-stage-' . $nonce);
		$temporaryPhar = Path::join($outputDirectory, '.' . $safeName . '.' . $nonce . '.tmp' . ArtifactFile::PHAR_EXTENSION);
		$this->filesystem->ensureDirectory($stage);
		$failure = null;
		$result = null;
		try {
			$provenance = $this->stage($projectRoot, $stage, $project);
			$this->filesystem->assertPortableTree($stage);
			$fileCount = $this->countFiles($stage);
			$this->writePhar($stage, $temporaryPhar);
			$this->validatePhar($temporaryPhar, $project->manifest->name, $provenance);
			$this->installer->install([['temporary' => $temporaryPhar, 'output' => $outputPath]], $overwrite);
			$result = new VirionBuildResult($project->manifest, $outputPath, $fileCount);
			$this->debug?->debugTranslated(TranslationKeys::DEBUG_BUILD_VIRION_INSTALLED, ['path' => $outputPath]);
		} catch (Throwable $error) {
			$this->debug?->debugFailure(TranslationKeys::DEBUG_BUILD_VIRION_FAILED, $error);
			$failure = $error instanceof BuildException
				? $error
				: new BuildException("Virion build failed for {$projectRoot}: {$error->getMessage()}", 0, $error);
		}

		$cleanupFailures = [];
		try {
			$this->filesystem->removeTree($stage);
		} catch (Throwable $error) {
			$cleanupFailures[] = $error->getMessage();
		}
		if (!PharSnapshot::remove($temporaryPhar)) {
			$cleanupFailures[] = "Cannot remove temporary PHAR {$temporaryPhar}.";
		}
		if ($failure !== null) {
			if ($cleanupFailures !== []) {
				throw new BuildException($failure->getMessage() . "\nCleanup also failed: " . implode("\n", $cleanupFailures), 0, $failure);
			}
			throw $failure;
		}
		if ($cleanupFailures !== []) {
			throw new BuildException('Virion output was created, but cleanup failed: ' . implode("\n", $cleanupFailures));
		}

		return $result;
	}

	private function stage(string $projectRoot, string $stage, VirionProject $project) : ?string
	{
		$antigen = $project->manifest->antigen;
		$name = $project->manifest->name;
		$this->filesystem->copyFile($projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::VIRION_MANIFEST, $stage . DIRECTORY_SEPARATOR . ArtifactFile::VIRION_MANIFEST);
		$source = $projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY;
		$antigenSource = $source . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $antigen);
		$sourceDestination = $stage . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY;
		if (is_dir($antigenSource)) {
			$this->filesystem->copyTree(
				$antigenSource,
				$sourceDestination . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $antigen),
				static fn(string $relative) : bool => !Path::isHiddenOrTemporary($relative),
			);
		} else {
			$this->filesystem->copyTree(
				$source,
				$sourceDestination,
				static fn(string $relative) : bool => !Path::isHiddenOrTemporary($relative),
			);
		}
		$resources = $projectRoot . DIRECTORY_SEPARATOR . 'resources';
		if (!is_file($projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST)
			&& is_dir($resources)
			&& (new FilesystemIterator($resources, FilesystemIterator::SKIP_DOTS))->valid()) {
			throw new BuildException(
				"Virion {$name} has root resources/. Only resources colocated under its source directory retain relative paths when bundled.",
			);
		}
		foreach (self::NOTICE_FILES as $notice) {
			$source = $projectRoot . DIRECTORY_SEPARATOR . $notice;
			if (is_file($source)) {
				$this->filesystem->copyFile($source, $stage . DIRECTORY_SEPARATOR . $notice);
			}
		}
		$provenance = $projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::VIRION_PROVENANCE_METADATA;
		if (!is_file($provenance)) {
			return null;
		}
		try {
			$this->provenance->verify($project);
		} catch (VirionException $error) {
			throw new BuildException($error->getMessage(), 0, $error);
		}
		$this->filesystem->copyFile($provenance, $stage . DIRECTORY_SEPARATOR . ArtifactFile::VIRION_PROVENANCE_METADATA);

		return $provenance;
	}

	private function writePhar(string $stage, string $path) : void
	{
		$this->archiveWriter->write($stage, $path);
	}

	private function validatePhar(string $path, string $name, ?string $provenance) : void
	{
		$phar = new Phar($path);
		if (!isset($phar[ArtifactFile::VIRION_MANIFEST]) || !isset($phar[ArtifactFile::SOURCE_DIRECTORY])) {
			throw new BuildException("Generated virion {$name} PHAR is missing virion.yml or src/.");
		}
		$manifest = $this->manifestReader->read('phar://' . str_replace('\\', '/', $path) . '/' . ArtifactFile::VIRION_MANIFEST);
		if ($manifest->name !== $name) {
			throw new BuildException("Generated virion manifest name {$manifest->name} does not match {$name}.");
		}
		if ($provenance !== null) {
			if (!isset($phar[ArtifactFile::VIRION_PROVENANCE_METADATA])) {
				throw new BuildException("Generated virion {$name} PHAR is missing " . ArtifactFile::VIRION_PROVENANCE_METADATA . '.');
			}
			$expected = hash_file(HashAlgorithm::SHA256, $provenance);
			$actual = hash(HashAlgorithm::SHA256, $phar[ArtifactFile::VIRION_PROVENANCE_METADATA]->getContent());
			if ($expected === false || !hash_equals($expected, $actual)) {
				throw new BuildException("Generated virion {$name} PHAR has altered provenance metadata.");
			}
		}
		$signature = $phar->getSignature();
		if ($signature['hash_type'] !== HashAlgorithm::PHAR_SHA256_SIGNATURE) {
			throw new BuildException("Generated virion {$name} PHAR has no SHA-256 signature.");
		}
		unset($phar);
	}

	private function countFiles(string $directory) : int
	{
		$count = 0;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $entry) {
			if ($entry instanceof SplFileInfo && $entry->isFile()) {
				++$count;
			}
		}

		return $count;
	}
}
