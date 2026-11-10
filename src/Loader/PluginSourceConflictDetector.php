<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Loader;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use FilesystemIterator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use function array_intersect_key;
use function array_merge;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function pathinfo;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;
use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

final class PluginSourceConflictDetector
{
	private readonly PhpSourceInspector $sourceInspector;

	public function __construct(
		private readonly PluginManifestReader $manifestReader,
		?PhpSourceInspector $sourceInspector = null,
		private readonly ?DebugLogger $debug = null,
	) {
		$this->sourceInspector = $sourceInspector ?? new PhpSourceInspector();
	}

	/** @return list<string> */
	public function detect(string $pluginsDirectory, ?string $excludedFolder = null, ?Translator $translator = null) : array
	{
		if (!is_dir($pluginsDirectory)) {
			return [];
		}

		/** @var array<string, list<string>> $folders */
		$folders = [];
		/** @var array<string, list<string>> $phars */
		$phars = [];
		/** @var array<string, string> $names */
		$names = [];
		$iterator = new FilesystemIterator($pluginsDirectory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo || Filesystem::isLink($entry->getPathname())) {
				continue;
			}
			$path = $entry->getPathname();
			try {
				if ($entry->isDir() && $this->isExcludedFolder($path, $excludedFolder)) {
					continue;
				}
				if ($entry->isDir() && is_file($path . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST)) {
					$name = $this->manifestReader->read($path . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST)->description->getName();
					$key = strtolower($name);
					$names[$key] = $name;
					$folders[$key][] = $path;
				} elseif ($entry->isFile() && strtolower($entry->getExtension()) === 'phar') {
					$phar = new Phar($path);
					if (!isset($phar[ArtifactFile::PLUGIN_MANIFEST])) {
						continue;
					}
					$name = $this->manifestReader->read('phar://' . str_replace('\\', '/', realpath($path) ?: $path) . '/' . ArtifactFile::PLUGIN_MANIFEST)->description->getName();
					$key = strtolower($name);
					$names[$key] = $name;
					$phars[$key][] = $path;
				}
			} catch (Throwable) {
				// Invalid entries are diagnosed by the registered loader.
			}
		}

		$warnings = [];
		$translator ??= Translator::fromResources();
		foreach (array_intersect_key($folders, $phars) as $key => $folderPaths) {
			$name = $names[$key] ?? $key;
			$pharPaths = $phars[$key];
			$warnings[] = $translator->translate('runtime.duplicate_sources', ['name' => $name]);
			$this->debug?->debug(
				"Plugin {$name} duplicate sources. Development folders: " . implode(', ', $folderPaths)
				. '; PHARs: ' . implode(', ', $pharPaths) . '.',
			);
		}

		return $warnings;
	}

	private function isExcludedFolder(string $path, ?string $excludedFolder) : bool
	{
		if ($excludedFolder === null) {
			return false;
		}

		$path = str_replace('\\', '/', realpath($path) ?: $path);
		$excludedFolder = str_replace('\\', '/', realpath($excludedFolder) ?: $excludedFolder);
		if (DIRECTORY_SEPARATOR === '\\') {
			$path = strtolower($path);
			$excludedFolder = strtolower($excludedFolder);
		}

		return rtrim($path, '/') === rtrim($excludedFolder, '/');
	}

	/**
	 * @param list<string> $virionAntigens
	 * @param list<string> $virionClasses
	 * @return list<string>
	 */
	public function detectVirionConflicts(string $pluginRoot, array $virionAntigens, array $virionClasses) : array
	{
		$sourceRoot = $pluginRoot . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY;
		if (!is_dir($sourceRoot)) {
			return [];
		}
		$antigens = [];
		foreach ($virionAntigens as $antigen) {
			$antigens[strtolower(trim($antigen, '\\'))] = trim($antigen, '\\');
		}
		$classes = [];
		foreach ($virionClasses as $class) {
			$classes[strtolower(trim($class, '\\'))] = trim($class, '\\');
		}

		$conflicts = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo || !$entry->isFile() || !$this->isPhpSourceFile($entry->getFilename())) {
				continue;
			}
			$symbols = $this->sourceInspector->inspect($entry->getPathname());
			foreach (array_merge($symbols->classes, $symbols->functions, $symbols->constants) as $declaration) {
				$key = strtolower(trim($declaration, '\\'));
				if (isset($classes[$key])) {
					$conflicts[] = "Plugin declaration {$declaration} shadows shared virion class {$classes[$key]}.";
					continue;
				}
				foreach ($antigens as $antigenKey => $antigen) {
					if ($this->isNamespaceMember($declaration, $antigenKey)) {
						$conflicts[] = "Plugin declaration {$declaration} overlaps shared virion antigen {$antigen}.";
						break;
					}
				}
			}
		}

		return array_values(array_unique($conflicts));
	}

	private function isNamespaceMember(string $candidate, string $prefix) : bool
	{
		$candidate = strtolower(trim($candidate, '\\'));
		$prefix = strtolower(trim($prefix, '\\'));

		return $candidate === $prefix || str_starts_with($candidate, $prefix . '\\');
	}

	private function isPhpSourceFile(string $filename) : bool
	{
		return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['php', 'inc', 'phtml'], true);
	}
}
