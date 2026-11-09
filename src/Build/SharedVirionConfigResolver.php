<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionName;
use AxolotlBucketLabs\DevTools\Virion\VirionPlatformRequirements;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use Throwable;
use function array_any;
use function array_filter;
use function array_map;
use function array_replace;
use function array_values;
use function count;
use function file_exists;
use function is_dir;
use function is_file;
use function realpath;
use function str_contains;
use function str_replace;
use function str_starts_with;
use const ARRAY_FILTER_USE_KEY;

final class SharedVirionConfigResolver
{
	/** @var array<string, string> */
	private array $aliases = [];

	/** @var array<string, string> */
	private array $aliasPackages = [];

	/** @var array<string, string> */
	private array $skippedLocations = [];

	private readonly Translator $translator;

	public function __construct(
		private readonly BuildConfigResolver $configResolver,
		private readonly VirionDiscovery $discovery,
		private readonly VirionProjectFactory $factory,
		private readonly bool $allowLocalComposer = true,
		private readonly ?DebugLogger $debug = null,
		?Translator $translator = null,
	) {
		$this->translator = $translator ?? Translator::fromResources();
	}

	/** @return list<VirionRequirement> */
	public function resolve(string $projectRoot, string $pluginName, string $virionsDirectory) : array
	{
		$requirements = $this->configResolver->resolve($projectRoot, $pluginName)->virions;
		if (!$this->allowLocalComposer || !array_any($requirements, static fn(VirionRequirement $requirement) : bool => $requirement->composerPackageSha256 !== null)) {
			return $requirements;
		}

		$plan = new ComposerVirionPlan();
		$packages = [];
		foreach ($plan->packages($projectRoot) as $name => $package) {
			$packages[VirionName::key(ComposerVirionPlan::localName($name))] = ['name' => $name, 'metadata' => $package];
		}
		$projects = [];
		$invalidNames = [];
		$hasUnopenedPrepared = false;
		foreach ($this->discovery->discover($virionsDirectory) as $location) {
			try {
				$projects[] = $this->factory->open($location);
			} catch (Throwable) {
				// The shared loader reports invalid packages and prevents PHAR fallback.
				$name = $this->factory->readFolderManifestName($location);
				if ($name !== null) {
					$invalidNames[VirionName::key($name)] = true;
				}
				$root = is_dir($location) ? $location : 'phar://' . str_replace('\\', '/', realpath($location) ?: $location);
				$provenancePath = $root . '/devtools-provenance.json';
				if (file_exists($provenancePath) || Filesystem::isLink($provenancePath)) {
					$hasUnopenedPrepared = true;
				}
			}
		}

		$aliases = [];
		$aliasPackages = [];
		$skippedLocations = [];
		foreach ($requirements as $index => $requirement) {
			$key = VirionName::key($requirement->name);
			$locked = $packages[$key] ?? null;
			if ($requirement->composerPackageSha256 === null || $locked === null || isset($invalidNames[$key])) {
				continue;
			}
			$package = $locked['metadata'];
			$packageName = $locked['name'];
			$named = array_values(array_filter($projects, static fn(VirionProject $project) : bool => VirionName::key($project->manifest->name) === $key));
			$folders = array_values(array_filter($named, static fn(VirionProject $project) : bool => !$project->phar));
			$named = $folders !== [] ? $folders : $named;
			if ($hasUnopenedPrepared || array_any($projects, fn(VirionProject $project) : bool => $this->isPreparedFor($project, $packageName, $package, $requirement, $plan))) {
				foreach ($projects as $project) {
					$path = $project->packageRoot . '/composer.json';
					if (!$project->phar && !file_exists($project->packageRoot . '/devtools-provenance.json')
						&& !Filesystem::isLink($project->packageRoot . '/devtools-provenance.json')
						&& is_file($path) && !Filesystem::isLink($path)
						&& ($plan->readJson($path)['name'] ?? null) === $packageName) {
						$skippedLocations[$project->location] = $this->translator->translate('virions.local.skipped_prepared', ['name' => $project->manifest->name, 'package' => $packageName]);
						try {
							if ($this->matchesLocal($project, $packageName, $package, $requirement, $plan)) {
								$aliases[VirionName::key($project->manifest->name)] = $requirement->name;
							}
						} catch (BuildException|VirionException $error) {
							$this->debug?->debug($error->getMessage());
						}
					}
				}
				continue;
			}
			$matches = [];
			$matchedLocations = [];
			foreach ($named !== [] ? $named : $projects as $project) {
				if (!$this->matchesLocal($project, $packageName, $package, $requirement, $plan)) {
					continue;
				}
				$matches[VirionName::key($project->manifest->name)] = $project->manifest->name;
				$matchedLocations[$project->location] = true;
			}
			if (count($matches) > 1) {
				throw new BuildException($this->translator->translate('virions.local.ambiguous_package', ['package' => $packageName]));
			}
			if ($matches === []) {
				continue;
			}
			$name = array_values($matches)[0];
			foreach ($projects as $project) {
				if (!$project->phar && VirionName::key($project->manifest->name) === VirionName::key($name)
					&& $requirement->matches($project->manifest->version) && !isset($matchedLocations[$project->location])) {
					throw new BuildException($this->translator->translate('virions.local.unverified_name', ['name' => $name, 'path' => $project->location]));
				}
			}
			$aliasKey = VirionName::key($name);
			$claimedBy = $aliasPackages[$aliasKey] ?? $this->aliasPackages[$aliasKey] ?? null;
			if ($claimedBy !== null && $claimedBy !== $packageName) {
				throw new BuildException($this->translator->translate('virions.local.name_conflict', ['name' => $name, 'first' => $claimedBy, 'second' => $packageName]));
			}
			$aliasPackages[$aliasKey] = $packageName;
			$runtimeName = $this->aliases[$key] ?? $name;
			$aliases[$key] = $runtimeName;
			$requirements[$index] = new VirionRequirement($runtimeName, $requirement->constraint->expression, $requirement->source);
			$this->debug?->debug($this->translator->translate('virions.local.selected_debug', ['name' => $name, 'package' => $packageName, 'project' => $projectRoot]));
		}
		$this->aliases = array_replace($this->aliases, $aliases);
		$this->aliasPackages = array_replace($this->aliasPackages, $aliasPackages);
		$this->skippedLocations = array_replace($this->skippedLocations, $skippedLocations);

		return array_map(fn(VirionRequirement $requirement) : VirionRequirement => new VirionRequirement(
			$this->aliases[VirionName::key($requirement->name)] ?? $requirement->name,
			$requirement->constraint->expression,
			$requirement->source,
			$requirement->composerPackageSha256,
		), $requirements);
	}

	/** @param array<string, mixed> $package */
	private function isPreparedFor(VirionProject $project, string $name, array $package, VirionRequirement $requirement, ComposerVirionPlan $plan) : bool
	{
		$canonicalName = VirionName::key($project->manifest->name) === VirionName::key($requirement->name);
		if ($project->phar && $canonicalName) {
			return true;
		}
		$path = $project->packageRoot . '/devtools-provenance.json';
		if (!file_exists($path) && !Filesystem::isLink($path)) {
			return false;
		}
		if ($canonicalName || $project->manifest->antigen === $plan->antigen($package)) {
			return true;
		}
		$provenance = $plan->readJson($path);
		return ($provenance['package'] ?? null) === $name
			|| ($provenance['composerPackageSha256'] ?? null) === $requirement->composerPackageSha256;
	}

	/** @param array<string, mixed> $package */
	private function matchesLocal(VirionProject $project, string $name, array $package, VirionRequirement $requirement, ComposerVirionPlan $plan) : bool
	{
		if ($project->phar || !is_file($project->packageRoot . '/composer.json')
			|| file_exists($project->packageRoot . '/devtools-provenance.json')
			|| Filesystem::isLink($project->packageRoot . '/devtools-provenance.json')) {
			return false;
		}
		$metadata = $plan->readJson($project->packageRoot . '/composer.json');
		if (($metadata['name'] ?? null) !== $name || !$requirement->matches($project->manifest->version)
			|| str_starts_with($plan->version($package), '0.0.0-commit.')
			|| (isset($metadata['version']) && $plan->version($metadata) !== $project->manifest->version)) {
			return false;
		}
		foreach (['autoload', 'require', 'extra'] as $field) {
			if (!$plan->sameMetadata($metadata[$field] ?? [], $package[$field] ?? [])) {
				throw new BuildException($this->translator->translate('virions.local.metadata_mismatch', ['name' => $project->manifest->name, 'field' => $field]));
			}
		}
		if ($project->manifest->antigen !== $plan->antigen($package)
			|| realpath($project->packageRoot . '/' . $plan->sourceDirectory($package)) !== realpath($project->sourceRoot)) {
			throw new BuildException($this->translator->translate('virions.local.layout_mismatch', ['name' => $project->manifest->name]));
		}
		$platform = array_filter(
			$plan->object($metadata['require'] ?? [], $project->packageRoot . '/composer.json'),
			static fn(string $name) : bool => !str_contains($name, '/') && !str_starts_with($name, 'composer-'),
			ARRAY_FILTER_USE_KEY,
		);
		(new VirionPlatformRequirements())->validate($platform, $project->packageRoot . '/composer.json');
		return true;
	}

	/** @return array<string, string> */
	public function aliases() : array
	{
		return $this->aliases;
	}

	/** @return array<string, string> */
	public function skippedLocations() : array
	{
		return $this->skippedLocations;
	}
}
