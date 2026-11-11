<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\HashAlgorithm;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionPlatformRequirements;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionSourceFingerprint;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function array_key_first;
use function array_keys;
use function array_reverse;
use function basename;
use function dirname;
use function file_exists;
use function file_put_contents;
use function hash_file;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_encode;
use function ksort;
use function realpath;
use function rename;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function var_export;
use function yaml_emit;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SORT_STRING;

final class ComposerVirionPreparer
{
	private const PREPARED_METADATA_SCHEMA_VERSION = 1;
	private const THIRD_PARTY_LICENSE_FILES = [
		'LICENSE',
		'LICENSE.md',
		'LICENSE.txt',
		'COPYING',
		'COPYING.md',
		'NOTICE',
		'NOTICE.md',
		'THIRD_PARTY_NOTICES.md',
	];

	public function __construct(private readonly Filesystem $filesystem) {}

	/** @return list<array<string, mixed>> */
	public function prepare(string $projectRoot, string $destination) : array
	{
		$plan = new ComposerVirionPlan();
		$packages = $plan->packages($projectRoot);
		if ($packages === []) {
			return [];
		}
		$projectRoot = realpath($projectRoot) ?: throw new BuildException('Cannot resolve project root.');
		$destination = rtrim($destination, '/\\');
		if (file_exists($destination) || Filesystem::isLink($destination)) {
			throw new BuildException("Refusing to replace existing dependency directory {$destination}. Prepare into a new directory, then select it with --virions.");
		}
		for ($component = dirname($destination); dirname($component) !== $component; $component = dirname($component)) {
			if (Filesystem::isLink($component)) {
				throw new BuildException("Refusing dependency output through symbolic link {$component}.");
			}
		}
		$resolvedParent = $this->resolveOutputParent(dirname($destination));
		if (Path::isInside($projectRoot . '/' . ArtifactFile::COMPOSER_VENDOR_DIRECTORY, $resolvedParent) || Path::isInside($projectRoot . '/' . ArtifactFile::SOURCE_DIRECTORY, $resolvedParent)) {
			throw new BuildException('Dependency output must be outside vendor/ and src/.');
		}
		$this->filesystem->ensureDirectory(dirname($destination));
		$parent = realpath(dirname($destination)) ?: throw new BuildException('Cannot resolve dependency output parent.');
		if (Path::isInside($projectRoot . '/' . ArtifactFile::COMPOSER_VENDOR_DIRECTORY, $parent) || Path::isInside($projectRoot . '/' . ArtifactFile::SOURCE_DIRECTORY, $parent)) {
			throw new BuildException('Dependency output must be outside vendor/ and src/.');
		}
		$composer = $plan->readJson($projectRoot . '/' . ArtifactFile::COMPOSER_MANIFEST);
		$config = $composer['config'] ?? [];
		if (is_array($config) && isset($config['vendor-dir']) && $config['vendor-dir'] !== 'vendor') {
			throw new BuildException('Composer preparation currently requires the standard vendor directory.');
		}
		$this->filesystem->assertNoSymbolicLinkComponents($projectRoot, ArtifactFile::COMPOSER_VENDOR_DIRECTORY . '/' . ArtifactFile::COMPOSER_INSTALLED_METADATA);
		$installed = $plan->readJson($projectRoot . '/' . ArtifactFile::COMPOSER_VENDOR_DIRECTORY . '/' . ArtifactFile::COMPOSER_INSTALLED_METADATA);
		$installedByName = [];
		$installedPackages = $installed['packages'] ?? [];
		if (!is_array($installedPackages)) {
			throw new BuildException('Invalid vendor/composer/installed.json packages list.');
		}
		foreach ($installedPackages as $package) {
			if (is_array($package) && is_string($package['name'] ?? null)) {
				$installedByName[$package['name']] = $package;
			}
		}
		$stage = $parent . '/.devtools-prepare-' . RandomToken::pathSuffix();
		$this->filesystem->ensureDirectory($stage);
		$result = [];
		try {
			$factory = new VirionProjectFactory(new VirionManifestReader(new YamlReader()));
			$scanner = new VirionClassScanner(new PhpSourceInspector());
			foreach ($packages as $name => $package) {
				$installedPackage = $installedByName[$name] ?? null;
				foreach (['version', 'source', 'dist', 'autoload', 'require', 'extra'] as $field) {
					if (!$plan->sameMetadata($installedPackage[$field] ?? null, $package[$field] ?? null)) {
						throw new BuildException("Installed Composer package {$name} differs from composer.lock ({$field}). Run composer install --no-dev --no-scripts --no-plugins again.");
					}
				}
			$relative = ArtifactFile::COMPOSER_VENDOR_DIRECTORY . '/' . $name;
				$this->filesystem->assertNoSymbolicLinkComponents($projectRoot, $relative . '/' . ArtifactFile::COMPOSER_MANIFEST);
				$source = $projectRoot . '/' . $relative;
				$sourceComposer = $plan->readJson($source . '/' . ArtifactFile::COMPOSER_MANIFEST);
				foreach (['name', 'autoload', 'require', 'extra'] as $field) {
					$sourceMetadata = $sourceComposer[$field] ?? null;
					$lockedMetadata = $package[$field] ?? null;
					if (in_array($field, ['autoload', 'require', 'extra'], true)) {
						$sourceMetadata = is_array($sourceMetadata) ? $sourceMetadata : [];
						$lockedMetadata = is_array($lockedMetadata) ? $lockedMetadata : [];
					}
					if (!$plan->sameMetadata($sourceMetadata, $lockedMetadata)) {
						throw new BuildException("Installed {$name}/composer.json differs from composer.lock ({$field}). Refusing changed package metadata.");
					}
				}
				$antigen = $plan->antigen($package);
				$sourceRelative = Path::normalizeRelativeDirectory($plan->sourceDirectory($package));
				$this->filesystem->assertNoSymbolicLinkComponents($source, $sourceRelative);
				$autoload = $package['autoload'] ?? null;
				if (is_array($autoload) && array_key_first($autoload) === 'classmap') {
					$classmap = $autoload['classmap'];
					if (!is_array($classmap) || array_keys($classmap) !== [0] || !is_string($classmap[0])) {
						throw new BuildException("Composer virion {$name} requires one classmap source directory.");
					}
					$classmapRoot = Path::normalizeRelativeDirectory($classmap[0]);
					$this->assertClassmapSource($source, $classmapRoot, $sourceRelative, $name);
				}
				if (is_dir($source . '/resources') && !$plan->excludesRootResources($package)) {
					throw new BuildException("Composer virion {$name} has root resources/. Only resources colocated under its PSR-4 source directory retain their relative paths when bundled.");
				}
				$localName = ComposerVirionPlan::localName($name);
				$target = $stage . '/' . $localName;
				if (file_exists($target)) {
					throw new BuildException("Composer package name collision at {$localName}.");
				}
				$sourceDirectory = $sourceRelative === '' ? $source : $source . '/' . $sourceRelative;
				$this->filesystem->copyTree($sourceDirectory, $target . '/' . ArtifactFile::SOURCE_DIRECTORY, static function (string $relative) : bool {
					if (Path::isSensitive($relative)) {
						throw new BuildException("Refusing sensitive package source file {$relative}.");
					}
					return !Path::isHiddenOrTemporary($relative);
				});
				$autoloadShims = $this->createCoLocatedClassShims($target . '/' . ArtifactFile::SOURCE_DIRECTORY, $antigen, $name);
				$requirements = [];
				foreach ($plan->dependencyNames($package['require'] ?? [], $name) as $dependency) {
					if ($dependency === 'pocketmine/pocketmine-mp' && !isset($packages[$dependency])) {
						continue;
					}
					$requirements[] = ['name' => ComposerVirionPlan::localName($dependency), 'version' => $plan->version($packages[$dependency])];
				}
				$manifest = ['name' => $localName, 'version' => $plan->version($package), 'antigen' => $antigen, 'php' => ['8.4'], 'virions' => $requirements];
				$platform = [];
				foreach ($plan->object($package['require'] ?? [], $name) as $dependency => $constraint) {
					if (!str_contains($dependency, '/') && !str_starts_with($dependency, 'composer-')) {
						$platform[$dependency] = $constraint;
					}
				}
				(new VirionPlatformRequirements())->validate($platform, $name);
				$manifest['composer-platform'] = $platform;
				$this->write($target . '/' . ArtifactFile::VIRION_MANIFEST, yaml_emit($manifest));
				foreach (self::THIRD_PARTY_LICENSE_FILES as $license) {
					if (is_file($source . '/' . $license)) {
						$this->filesystem->copyFile($source . '/' . $license, $target . '/' . $license);
					}
				}
				$metadata = ['package' => $name, 'name' => $localName, 'version' => $manifest['version'], 'composerVersion' => $package['version'], 'antigen' => $antigen, 'source' => $package['source'] ?? null, 'dist' => $package['dist'] ?? null, 'composerLockSha256' => hash_file(HashAlgorithm::SHA256, $projectRoot . '/' . ArtifactFile::COMPOSER_LOCK), 'autoloadShims' => $autoloadShims];
				$metadata['sourceSha256'] = (new VirionSourceFingerprint())->hash($target . '/' . ArtifactFile::SOURCE_DIRECTORY);
				$metadata['manifestSha256'] = hash_file(HashAlgorithm::SHA256, $target . '/' . ArtifactFile::VIRION_MANIFEST);
				$metadata['composerPackageSha256'] = $plan->fingerprint($package);
				$this->write($target . '/' . ArtifactFile::VIRION_PROVENANCE_METADATA, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
				$project = $factory->open($target);
				$classes = $scanner->scan($project);
				if ($classes === []) {
					throw new BuildException("Composer virion {$name} contains no autoloadable classes.");
				}
				foreach ($classes as $class) {
					if (!str_starts_with($class, $antigen . '\\')) {
						throw new BuildException("Composer virion {$name} declares {$class} outside {$antigen}.");
					}
				}
				$result[] = $metadata;
			}
			$this->write($stage . '/' . ArtifactFile::PREPARED_VIRION_METADATA, json_encode(['schemaVersion' => self::PREPARED_METADATA_SCHEMA_VERSION, 'packages' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
			if (!rename($stage, $destination)) {
				throw new BuildException("Cannot install prepared dependencies at {$destination}.");
			}
		} finally {
			$this->filesystem->removeTree($stage);
		}
		return $result;
	}

	private function resolveOutputParent(string $path) : string
	{
		$missing = [];
		while (!is_dir($path)) {
			$component = basename($path);
			$parent = dirname($path);
			if ($parent === $path) {
				throw new BuildException('Cannot resolve dependency output parent.');
			}
			$missing[] = $component;
			$path = $parent;
		}
		$resolved = realpath($path) ?: throw new BuildException('Cannot resolve dependency output parent.');
		foreach (array_reverse($missing) as $component) {
			$resolved = $component === '..' ? dirname($resolved) : Path::join($resolved, $component);
		}
		return $resolved;
	}

	/** @return array<string, string> */
	private function createCoLocatedClassShims(string $root, string $antigen, string $package) : array
	{
		$inspector = new PhpSourceInspector();
		$declarations = [];
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo || !$entry->isFile() || !str_ends_with(strtolower($entry->getFilename()), ArtifactFile::PHP_SOURCE_EXTENSION)) {
				continue;
			}
			$relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
			$files[$relative] = $inspector->inspect($entry->getPathname())->classes;
			foreach ($files[$relative] as $class) {
				$key = strtolower($class);
				if (isset($declarations[$key])) {
					throw new BuildException("Composer virion {$package} declares {$class} in both {$declarations[$key]} and {$relative}.");
				}
				$declarations[$key] = $relative;
			}
		}
		ksort($files, SORT_STRING);
		$shims = [];
		foreach ($files as $relative => $classes) {
			$primary = $antigen . '\\' . str_replace('/', '\\', substr($relative, 0, -strlen(ArtifactFile::PHP_SOURCE_EXTENSION)));
			if (!in_array($primary, $classes, true)) {
				continue;
			}
			foreach ($classes as $class) {
				if ($class === $primary || !str_starts_with($class, $antigen . '\\')) {
					continue;
				}
				$expected = str_replace('\\', '/', substr($class, strlen($antigen) + 1)) . ArtifactFile::PHP_SOURCE_EXTENSION;
				if (dirname($expected) !== dirname($relative) || file_exists($root . '/' . $expected)) {
					continue;
				}
				$origin = basename($relative);
				$this->write($root . '/' . $expected, "<?php\n\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/' . " . var_export($origin, true) . ";\n");
				$shims[$expected] = $relative;
			}
		}
		ksort($shims, SORT_STRING);
		return $shims;
	}

	private function assertClassmapSource(string $source, string $classmapRoot, string $sourceRelative, string $name) : void
	{
		if ($classmapRoot !== '' && $sourceRelative !== $classmapRoot && !str_starts_with($sourceRelative, $classmapRoot . '/')) {
			throw new BuildException("Composer virion {$name} classmap source is outside its declared namespace tree.");
		}
		$this->filesystem->assertNoSymbolicLinkComponents($source, $classmapRoot);
		$root = $classmapRoot === '' ? $source : $source . '/' . $classmapRoot;
		if (!is_dir($root)) {
			throw new BuildException("Composer virion {$name} classmap root does not exist: {$classmapRoot}.");
		}
		$namespaceDirectory = $classmapRoot === ''
			? $sourceRelative . '/'
			: substr($sourceRelative, strlen($classmapRoot) + 1) . '/';
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo) {
				continue;
			}
			if (Filesystem::isLink($entry->getPathname())) {
				throw new BuildException("Composer virion {$name} classmap contains a symbolic link: {$entry->getPathname()}.");
			}
			if (!$entry->isFile() || !str_ends_with(strtolower($entry->getFilename()), ArtifactFile::PHP_SOURCE_EXTENSION)) {
				continue;
			}
			$relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
			if (!str_starts_with($relative, $namespaceDirectory)) {
				throw new BuildException("Composer virion {$name} classmap PHP source is outside {$sourceRelative}: {$relative}.");
			}
		}
	}

	private function write(string $path, string $contents) : void
	{
		if (file_put_contents($path, $contents) === false) {
			throw new BuildException("Cannot write prepared dependency metadata {$path}.");
		}
	}
}
