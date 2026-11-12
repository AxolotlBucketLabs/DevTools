<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Loader;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Validation\PluginProjectValidator;
use Closure;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginDescriptionParseException;
use pocketmine\plugin\PluginLoader;
use pocketmine\thread\ThreadSafeClassLoader;
use RuntimeException;
use Throwable;
use function basename;
use function implode;
use function is_dir;
use function is_file;
use function realpath;
use function str_replace;
use function strtolower;
use const DIRECTORY_SEPARATOR;

final class FolderPluginLoader implements PluginLoader
{
	/** @var array<string, true> */
	private array $registeredPaths = [];
	private readonly Translator $translator;

	/**
	 * @param (Closure(string): list<string>)|null $virionConflictChecker
	 */
	public function __construct(
		private readonly ThreadSafeClassLoader $classLoader,
		private readonly PluginManifestReader $manifestReader,
		private readonly PluginProjectValidator $validator,
		?string $excludedProject = null,
		private readonly ?Closure $virionConflictChecker = null,
		private readonly ?PluginDependencyIndex $dependencies = null,
		private readonly ?DebugLogger $debug = null,
		?Translator $translator = null,
	) {
		$this->translator = $translator ?? Translator::fromResources();
		$this->excludedProject = $excludedProject === null ? null : self::normalizePath($excludedProject);
	}

	private readonly ?string $excludedProject;

	public function canLoadPlugin(string $path) : bool
	{
		if (!is_dir($path) || Filesystem::isLink($path) || Path::isExcluded(basename($path)) || $this->isExcludedProject($path)) {
			return false;
		}

		return is_file($path . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST) || is_dir($path . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY);
	}

	public function loadPlugin(string $file) : void
	{
		if ($this->isExcludedProject($file)) {
			return;
		}

		$root = realpath($file);
		if ($root === false) {
			throw new RuntimeException($this->loadFailure($file, "The folder path cannot be resolved at {$file}."));
		}
		$normalized = str_replace('\\', '/', $root);
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_FOLDER_PLUGIN_VALIDATING, ['path' => $root]);
		if (isset($this->registeredPaths[$normalized])) {
			return;
		}
		$configurationFailure = $this->dependencies?->configurationFailure($root);
		if ($configurationFailure !== null) {
			throw new RuntimeException($this->loadFailure($root, $configurationFailure));
		}

		$validation = $this->validator->validate($root);
		if ($validation->hasErrors()) {
			$messages = [];
			foreach ($validation->issues() as $issue) {
				if ($issue->severity->value === 'error') {
					$messages[] = $issue->format($this->translator);
				}
			}
			throw new RuntimeException($this->loadFailure($root, implode("\n\n", $messages)));
		}
		$dependencyFailure = $this->dependencies?->dependencyFailure($root);
		if ($dependencyFailure !== null) {
			throw new RuntimeException($this->loadFailure($root, $dependencyFailure));
		}

		try {
			$manifest = $this->manifestReader->read($root . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST);
		} catch (Throwable $error) {
			throw new RuntimeException($this->loadFailure($root, $error->getMessage()), 0, $error);
		}
		if ($this->virionConflictChecker !== null) {
			$conflicts = ($this->virionConflictChecker)($root);
			if ($conflicts !== []) {
				throw new RuntimeException($this->loadFailure($root, implode("\n- ", $conflicts)));
			}
		}
		$this->classLoader->addPath(
			$manifest->description->getSrcNamespacePrefix(),
			$root . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY,
		);
		$this->registeredPaths[$normalized] = true;
		$this->debug?->debugTranslated(TranslationKeys::DEBUG_FOLDER_PLUGIN_REGISTERED, ['name' => $manifest->description->getName()]);
	}

	public function getPluginDescription(string $file) : ?PluginDescription
	{
		if (!$this->canLoadPlugin($file)) {
			return null;
		}
		if (!is_file($file . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST)) {
			throw new PluginDescriptionParseException(
				$this->loadFailure($file, "This folder contains src/ and looks like a plugin, but plugin.yml is missing. Create plugin.yml with name, version, main, and api fields."),
			);
		}
		$configurationFailure = $this->dependencies?->configurationFailure($file);
		if ($configurationFailure !== null) {
			throw new PluginDescriptionParseException(
				$this->loadFailure($file, $configurationFailure),
			);
		}
		$validation = $this->validator->validate($file);
		if ($validation->hasErrors()) {
			$messages = [];
			foreach ($validation->issues() as $issue) {
				if ($issue->severity->value === 'error') {
					$messages[] = $issue->format($this->translator);
				}
			}
			throw new PluginDescriptionParseException($this->loadFailure($file, implode("\n\n", $messages)));
		}
		$dependencyFailure = $this->dependencies?->dependencyFailure($file);
		if ($dependencyFailure !== null) {
			throw new PluginDescriptionParseException($this->loadFailure($file, $dependencyFailure));
		}

		try {
			return $this->manifestReader->read($file . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST)->description;
		} catch (Throwable $error) {
			throw new PluginDescriptionParseException($this->loadFailure($file, $error->getMessage()), 0, $error);
		}
	}

	public function getAccessProtocol() : string
	{
		return '';
	}

	private function loadFailure(string $project, string $detail) : string
	{
		return $this->translator->translate(TranslationKeys::RUNTIME_PLUGIN_LOAD_FAILED, ['project' => $project, 'error' => $detail]);
	}

	private function isExcludedProject(string $path) : bool
	{
		if ($this->excludedProject === null) {
			return false;
		}

		$resolved = realpath($path);

		return $resolved !== false && self::normalizePath($resolved) === $this->excludedProject;
	}

	private static function normalizePath(string $path) : string
	{
		$resolved = realpath($path) ?: $path;
		$normalized = str_replace('\\', '/', $resolved);

		return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
	}
}
