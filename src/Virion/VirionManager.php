<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use pocketmine\plugin\ApiVersion;
use Throwable;
use function array_map;
use function class_exists;
use function count;
use function defined;
use function enum_exists;
use function function_exists;
use function implode;
use function interface_exists;
use function ltrim;
use function realpath;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trait_exists;

final class VirionManager
{
	/** @var array<string, true> */
	private array $processedLocations = [];

	/** @var array<string, 'loading'|'loaded'|'failed'> */
	private array $graphLoadStates = [];

	private readonly VirionGraphPreflight $graphPreflight;
	private readonly VirionSelectionPlanner $selectionPlanner;

	/** @var array<string, true> */
	private array $graphReadyProjects = [];

	public function __construct(
		private readonly VirionDiscovery $discovery,
		private readonly VirionProjectFactory $projectFactory,
		private readonly VirionClassScanner $classScanner,
		private readonly ClassLoaderRegistrar $registrar,
		private readonly VirionRegistry $registry,
		VirionProjectSelector $selector,
		private readonly string $serverApiVersion,
		private readonly ?DebugLogger $debug = null,
	) {
		$this->graphPreflight = new VirionGraphPreflight($this->inspectProject(...));
		$this->selectionPlanner = new VirionSelectionPlanner($selector, $this->assertCompatible(...));
	}

	/**
	 * @param list<VirionRequirement> $requirements
	 * @param array<string, string>   $requirementAliases
	 * @param array<string, string>   $skippedLocations
	 */
	public function discoverAndLoad(string $directory, array $requirements = [], array $requirementAliases = [], array $skippedLocations = []) : VirionRegistry
	{
		$this->debug?->debug('Loading shared virions from ' . $directory . ' with ' . count($requirements) . ' project requirement(s).');
		/** @var array<string, list<array{string, VirionProject}>> $projectsByName */
		$projectsByName = [];
		/** @var array<string, string> $invalidFolderFailures */
		$invalidFolderFailures = [];
		$locations = $this->discovery->discover($directory);
		$this->debug?->debug('Discovered ' . count($locations) . ' shared virion location(s).');
		foreach ($locations as $location) {
			try {
				$project = $this->projectFactory->open($location);
				if (isset($skippedLocations[$project->location])) {
					$this->recordUnloaded(new VirionRegistryEntry($location, $project->manifest, VirionStatus::SKIPPED, $skippedLocations[$project->location]));
					continue;
				}
				if ($requirementAliases !== [] && $project->manifest->requirements !== []) {
					$manifest = $project->manifest;
					$mappedRequirements = array_map(
						static fn(VirionRequirement $requirement) : VirionRequirement => new VirionRequirement(
							$requirementAliases[VirionName::key($requirement->name)] ?? $requirement->name,
							$requirement->constraint->expression,
							$requirement->source,
							$requirement->composerPackageSha256,
						),
						$manifest->requirements,
					);
					$project = new VirionProject($project->location, $project->sourceRoot, new VirionManifest(
						$manifest->name,
						$manifest->version,
						$manifest->antigen,
						$manifest->api,
						$manifest->php,
						$manifest->raw,
						$manifest->path,
						$mappedRequirements,
					), $project->phar, $project->packageRoot);
				}
				$projectsByName[VirionName::key($project->manifest->name)][] = [$location, $project];
			} catch (Throwable $error) {
				$name = $this->projectFactory->readFolderManifestName($location);
				if ($name !== null) {
					$invalidFolderFailures[VirionName::key($name)] = $error->getMessage();
				}
				$this->load($location);
			}
		}
		$plan = $this->selectionPlanner->plan($projectsByName, $requirements, $directory, $invalidFolderFailures, $requirementAliases !== []);
		foreach ($plan->beforeLoad as $entry) {
			if ($entry->manifest === null) {
				$this->registry->record($entry);
			} else {
				$this->recordUnloaded($entry);
			}
		}
		if ($plan->blocked) {
			return $this->registry;
		}
		$selectedProjects = $plan->selectedProjects;
		$preflight = $this->graphPreflight->check($selectedProjects, $this->registry->entries(), $this->registry->loaded());
		$this->graphReadyProjects = $preflight->readyProjects;
		foreach ($preflight->failures as $failure) {
			$this->processedLocations[$this->canonicalLocation($failure->location)] = true;
			$this->registry->record($failure);
		}
		foreach ($plan->actions as $action) {
			if ($action['type'] === 'selected') {
				$this->loadSelectedProject(VirionName::key($action['project']->manifest->name), $selectedProjects);
				continue;
			}
			$this->recordUnloaded(new VirionRegistryEntry($action['location'], $action['project']->manifest, VirionStatus::SKIPPED, $action['message']));
		}
		foreach ($plan->afterLoad as $entry) {
			$this->registry->record($entry);
		}

		$this->debug?->debug('Shared virion loading finished with ' . count($this->registry->loaded()) . ' loaded virion(s) and ' . count($this->registry->entries()) . ' registry entr' . (count($this->registry->entries()) === 1 ? 'y' : 'ies') . '.');

		return $this->registry;
	}

	/** @param array<string, array{string, VirionProject}> $selectedProjects */
	private function loadSelectedProject(string $nameKey, array $selectedProjects) : bool
	{
		if (!isset($this->graphReadyProjects[$nameKey])) {
			$this->graphLoadStates[$nameKey] = 'failed';

			return false;
		}
		$state = $this->graphLoadStates[$nameKey] ?? null;
		if ($state === 'loaded') {
			return true;
		}
		if ($state === 'failed') {
			return false;
		}
		if ($state === 'loading') {
			return true;
		}
		if (!isset($selectedProjects[$nameKey])) {
			$this->graphLoadStates[$nameKey] = 'failed';

			return false;
		}

		$this->graphLoadStates[$nameKey] = 'loading';
		[$location, $project] = $selectedProjects[$nameKey];
		$this->debug?->debug("Loading selected virion {$project->manifest->name}@{$project->manifest->version} from {$location}.");
		foreach ($project->manifest->requirements as $requirement) {
			$dependencyKey = VirionName::key($requirement->name);
			if (!isset($selectedProjects[$dependencyKey])) {
				$this->recordDependencyFailure(
					$location,
					$project,
					"Required virion {$requirement->name} was not selected for {$project->manifest->name}.",
				);
				$this->graphLoadStates[$nameKey] = 'failed';

				return false;
			}
			if ($this->loadSelectedProject($dependencyKey, $selectedProjects)) {
				continue;
			}
			$dependency = $this->entryForLocation($selectedProjects[$dependencyKey][0]);
			$detail = $dependency === null ? 'The dependency failed before it could be diagnosed.' : $dependency->message;
			$this->recordDependencyFailure(
				$location,
				$project,
				"Cannot load {$project->manifest->name}: dependency {$requirement->name} failed. {$detail}",
			);
			$this->graphLoadStates[$nameKey] = 'failed';

			return false;
		}

		$entry = $this->load($location);
		$loaded = $entry?->status === VirionStatus::LOADED;
		$this->graphLoadStates[$nameKey] = $loaded ? 'loaded' : 'failed';
		$this->debug?->debug($loaded
			? "Loaded virion {$project->manifest->name}."
			: "Failed to load virion {$project->manifest->name}.");

		return $loaded;
	}

	private function inspectProject(VirionProject $project) : VirionSourceSymbols
	{
		$this->assertCompatible($project->manifest);
		$symbols = $this->classScanner->scanSymbols($project);
		if ($symbols->classes === []) {
			throw new VirionException("Cannot load {$project->manifest->name}: no PHP classes were found in {$project->sourceRoot}.");
		}
		$this->assertPreflightSymbolsAvailable($project, $symbols);

		return $symbols;
	}

	private function assertPreflightSymbolsAvailable(VirionProject $project, VirionSourceSymbols $symbols) : void
	{
		foreach ($symbols->classes as $class) {
			$this->assertClassIsNotLoaded($class, $project->manifest->name);
			$existing = $this->registry->findByClass($class);
			if ($existing !== null) {
				throw new VirionException("Class {$class} is already provided by {$existing->requireManifest()->name}.");
			}
		}
		foreach ($symbols->unconditionalFunctions as $function) {
			if (function_exists($function)) {
				$origin = 'the server or another plugin';
				try {
					$file = (new \ReflectionFunction($function))->getFileName();
					if ($file !== false) {
						$origin = $file;
					}
				} catch (\ReflectionException) {
				}
				throw new VirionException(
					"Cannot load {$project->manifest->name}. Function {$function} is already loaded from {$origin}. Refusing to register a second implementation.",
				);
			}
			$existing = $this->registry->findByFunction($function);
			if ($existing !== null) {
				throw new VirionException("Function {$function} is already provided by {$existing->requireManifest()->name}.");
			}
		}
		foreach ($symbols->constants as $constant) {
			if (defined($constant)) {
				throw new VirionException(
					"Cannot load {$project->manifest->name}. Constant {$constant} is already defined. Refusing to register a second implementation.",
				);
			}
			$existing = $this->registry->findByConstant($constant);
			if ($existing !== null) {
				throw new VirionException("Constant {$constant} is already provided by {$existing->requireManifest()->name}.");
			}
		}
	}

	private function recordDependencyFailure(string $location, VirionProject $project, string $message) : void
	{
		$this->processedLocations[$this->canonicalLocation($location)] = true;
		$this->registry->record(new VirionRegistryEntry(
			$location,
			$project->manifest,
			VirionStatus::FAILED,
			$message,
		));
	}

	private function entryForLocation(string $location) : ?VirionRegistryEntry
	{
		$canonical = $this->canonicalLocation($location);
		foreach ($this->registry->entries() as $entry) {
			if ($this->canonicalLocation($entry->location) === $canonical) {
				return $entry;
			}
		}

		return null;
	}

	public function load(string $location) : ?VirionRegistryEntry
	{
		$locationKey = $this->canonicalLocation($location);
		if (isset($this->processedLocations[$locationKey])) {
			foreach ($this->registry->entries() as $entry) {
				if ($this->canonicalLocation($entry->location) === $locationKey) {
					return $entry;
				}
			}

			return null;
		}
		$this->processedLocations[$locationKey] = true;

		try {
			$project = $this->projectFactory->open($location);
			$manifest = $project->manifest;
			$symbols = $this->inspectProject($project);
			$classes = $symbols->classes;

			$existingName = $this->registry->findByName($manifest->name);
			if ($existingName !== null) {
				throw new VirionException("Virion name {$manifest->name} is already loaded from {$existingName->location}.");
			}
			foreach ($this->registry->loaded() as $loaded) {
				$loadedManifest = $loaded->requireManifest();
				$loadedAntigen = $loadedManifest->antigen;
				if ($this->isNamespaceMember($manifest->antigen, $loadedAntigen) || $this->isNamespaceMember($loadedAntigen, $manifest->antigen)) {
					throw new VirionException("Namespace {$manifest->antigen} conflicts with {$loadedAntigen} from {$loadedManifest->name}.");
				}
			}

			$this->registrar->register($manifest->antigen, $project->sourceRoot);
			$async = $this->registrar->supportsAsyncWorkers();
			$this->debug?->debug(
				"Virion {$manifest->name} registered on the shared class loader. " . $this->registrar->asyncSupportDescription(),
			);
			$message = "Loaded {$manifest->name} {$manifest->version} ({$manifest->antigen}).";
			$entry = new VirionRegistryEntry(
				$location,
				$manifest,
				VirionStatus::LOADED,
				$message,
				$classes,
				$async,
				$symbols->unconditionalFunctions,
				$symbols->constants,
			);
		} catch (Throwable $error) {
			$manifest = isset($project) ? $project->manifest : null;
			$status = $this->looksLikeConflict($error->getMessage()) ? VirionStatus::CONFLICT : VirionStatus::FAILED;
			$entry = new VirionRegistryEntry($location, $manifest, $status, $error->getMessage());
		}

		$this->registry->record($entry);

		return $entry;
	}

	private function assertClassIsNotLoaded(string $class, string $virionName) : void
	{
		if (!class_exists($class, false)
			&& !interface_exists($class, false)
			&& !trait_exists($class, false)
			&& !enum_exists($class, false)) {
			return;
		}

		$origin = 'the server or another plugin';
		try {
			$file = (new \ReflectionClass($class))->getFileName();
			if ($file !== false) {
				$origin = $file;
			}
		} catch (\ReflectionException) {
		}

		throw new VirionException(
			"Cannot load {$virionName}. Class {$class} is already loaded from {$origin}. Refusing to register a second implementation.",
		);
	}

	private function assertCompatible(VirionManifest $manifest) : void
	{
		(new VirionPlatformRequirements())->validateManifest($manifest);
		if ($manifest->api !== [] && !ApiVersion::isCompatible($this->serverApiVersion, $manifest->api)) {
			throw new VirionException(
				"Cannot load {$manifest->name}: it declares API " . implode(', ', $manifest->api) . ", but this server provides {$this->serverApiVersion}.",
			);
		}
	}

	private function canonicalLocation(string $location) : string
	{
		$resolved = realpath($location);

		return str_replace('\\', '/', $resolved === false ? $location : $resolved);
	}

	private function isNamespaceMember(string $candidate, string $prefix) : bool
	{
		$candidate = strtolower(ltrim($candidate, '\\'));
		$prefix = strtolower(ltrim($prefix, '\\'));

		return $candidate === $prefix || str_starts_with($candidate, $prefix . '\\');
	}

	private function looksLikeConflict(string $message) : bool
	{
		$message = strtolower($message);

		return str_contains($message, 'already') || str_contains($message, 'conflict');
	}

	private function recordUnloaded(VirionRegistryEntry $entry) : void
	{
		$locationKey = $this->canonicalLocation($entry->location);
		if (isset($this->processedLocations[$locationKey])) {
			return;
		}
		$this->processedLocations[$locationKey] = true;
		$this->registry->record($entry);
	}
}
