<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Runtime;

use AxolotlBucketLabs\DevTools\Build\SharedVirionConfigResolver;

use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Loader\PluginDependencyIndex;
use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use Closure;
use Throwable;
use function count;
use const DIRECTORY_SEPARATOR;

final class PluginDependencyCollector
{
	/** @param Closure(string): void $warning */
	public function __construct(
		private readonly FolderPluginDiscovery $discovery,
		private readonly PluginManifestReader $manifestReader,
		private readonly SharedVirionConfigResolver $resolver,
		private readonly VirionRequirementChecker $checker,
		private readonly Closure $warning,
		private readonly Translator $translator,
		private readonly ?DebugLogger $debug = null,
	) {}

	public function collect(string $pluginsDirectory, string $virionsDirectory) : PluginDependencyIndex
	{
		$requirementsByProject = [];
		$configurationFailures = [];
		$projects = $this->discovery->discover($pluginsDirectory);
		$this->debug?->debug('Discovered ' . count($projects) . ' development plugin folder(s).');
		foreach ($projects as $project) {
			try {
				$manifest = $this->manifestReader->read($project . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST);
			} catch (Throwable $error) {
				($this->warning)($this->translator->translate(TranslationKeys::RUNTIME_PLUGIN_MANIFEST_FAILED, ['project' => $project, 'error' => $error->getMessage()]));
				continue;
			}
			try {
				$requirements = $this->resolver->resolve($project, $manifest->description->getName(), $virionsDirectory);
				$requirementsByProject[$project] = $requirements;
			} catch (Throwable $error) {
				$configurationFailures[$project] = $error->getMessage();
				($this->warning)($this->translator->translate(TranslationKeys::RUNTIME_SHARED_REQUIREMENTS_FAILED, ['project' => $project, 'error' => $error->getMessage()]));
			}
		}
		return new PluginDependencyIndex($requirementsByProject, $configurationFailures, $this->checker);
	}
}
