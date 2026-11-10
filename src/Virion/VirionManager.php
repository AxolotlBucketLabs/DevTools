<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use pocketmine\plugin\ApiVersion;
use Throwable;
use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function defined;
use function enum_exists;
use function function_exists;
use function implode;
use function interface_exists;
use function ksort;
use function ltrim;
use function realpath;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trait_exists;
use const SORT_STRING;

final class VirionManager
{
	/** @var array<string, true> */
	private array $processedLocations = [];

	/** @var array<string, 'loading'|'loaded'|'failed'> */
	private array $graphLoadStates = [];

	private readonly VirionGraphPreflight $graphPreflight;

	/** @var array<string, true> */
	private array $graphReadyProjects = [];

	public function __construct(
		private readonly VirionDiscovery $discovery,
		private readonly VirionProjectFactory $projectFactory,
		private readonly VirionClassScanner $classScanner,
		private readonly ClassLoaderRegistrar $registrar,
		private readonly VirionRegistry $registry,
		private readonly VirionProjectSelector $selector,
		private readonly string $serverApiVersion,
		private readonly ?DebugLogger $debug = null,
	) {
		$this->graphPreflight = new VirionGraphPreflight($this->inspectSelectedProject(...));
	}

	/**
	 * @param list<VirionRequirement> $requirements
	 * @param array<string, string>   $requirementAliases
	 * @param array<string, string>   $skippedLocations
	 */
	public function discoverAndLoad(string $directory, array $requirements = [], array $requirementAliases = [], array $skippedLocations = []) : VirionRegistry
	{
		$this->debug?->debug('Loading shared virions from ' . $directory . ' with ' . count($requirements) . ' project requirement(s).');
		/** @var array<string, list<VirionRequirement>> $requirementsByName */
		$requirementsByName = [];
		$hasComposerRequirements = false;
		foreach ($requirements as $requirement) {
			$requirementsByName[VirionName::key($requirement->name)][] = $requirement;
			$hasComposerRequirements = $hasComposerRequirements || $requirement->composerPackageSha256 !== null;
		}

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
					$this->recordUnloaded($location, $project, VirionStatus::SKIPPED, $skippedLocations[$project->location]);
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
		foreach ($invalidFolderFailures as $nameKey => $errorMessage) {
			if (!isset($projectsByName[$nameKey])) {
				continue;
			}
			$hasFolder = false;
			foreach ($projectsByName[$nameKey] as [, $candidate]) {
				if (!$candidate->phar) {
					$hasFolder = true;
					break;
				}
			}
			if ($hasFolder) {
				continue;
			}
			foreach ($projectsByName[$nameKey] as [$location, $candidate]) {
				$this->recordUnloaded(
					$location,
					$candidate,
					VirionStatus::SKIPPED,
					"Skipped {$candidate->manifest->name} {$candidate->manifest->version} at {$location}: a development folder with the same name could not be loaded. {$errorMessage}",
				);
			}
			unset($projectsByName[$nameKey], $requirementsByName[$nameKey]);
		}
		ksort($projectsByName, SORT_STRING);
		$hasTransitive = false;
		$available = [];
		/** @var array<string, array{string, VirionProject}> $selectedProjects */
		$selectedProjects = [];
		/** @var array<string, list<array{type: 'selected'|'skipped', location: string, project: VirionProject, message?: string}>> $selectionActions */
		$selectionActions = [];
		foreach ($projectsByName as $nameKey => $candidates) {
			foreach ($this->preferredCandidates($candidates) as [, $project]) {
				$hasTransitive = $hasTransitive || $project->manifest->requirements !== [];
				try {
					$this->assertCompatible($project->manifest);
					$available[$nameKey][] = $project;
				} catch (VirionException) {
				}
			}
		}
		if ($hasTransitive || $hasComposerRequirements || $requirementAliases !== []) {
			$graphRequirements = $requirements;
			foreach ($available as $nameKey => $projects) {
				if (!isset($requirementsByName[$nameKey])) {
					$graphRequirements[] = new VirionRequirement($projects[0]->manifest->name, '*', $directory);
				}
			}
			try {
				foreach ((new VirionDependencyResolver($this->selector))->resolve($available, $graphRequirements) as $selected) {
					$requirementsByName[VirionName::key($selected->manifest->name)][] = new VirionRequirement($selected->manifest->name, $selected->manifest->version, 'resolved shared dependency graph');
				}
			} catch (VirionException $error) {
				if ($projectsByName === []) {
					$this->registry->record(new VirionRegistryEntry($directory, null, VirionStatus::FAILED, $error->getMessage()));
				}
				foreach ($projectsByName as $candidates) {
					foreach ($candidates as [$location, $project]) {
						$this->recordUnloaded($location, $project, VirionStatus::CONFLICT, $error->getMessage());
					}
				}
				return $this->registry;
			}
		}
		foreach ($projectsByName as $nameKey => $candidates) {
			$preferredCandidates = $this->preferredCandidates($candidates);
			$compatibleCandidates = [];
			foreach ($preferredCandidates as [$location, $candidateProject]) {
				try {
					$this->assertCompatible($candidateProject->manifest);
					$compatibleCandidates[] = [$location, $candidateProject];
				} catch (VirionException $error) {
					$this->recordUnloaded(
						$location,
						$candidateProject,
						VirionStatus::FAILED,
						$error->getMessage(),
					);
				}
			}
			if ($compatibleCandidates === []) {
				$this->recordPreferredPhars($candidates);
				unset($requirementsByName[$nameKey]);
				continue;
			}
			$projects = [];
			foreach ($compatibleCandidates as [, $candidateProject]) {
				$projects[] = $candidateProject;
			}
			$name = $projects[0]->manifest->name;
			$nameRequirements = $requirementsByName[$nameKey] ?? [];
			try {
				$selected = $this->selector->select($name, $projects, $nameRequirements);
			} catch (VirionException $error) {
				foreach ($compatibleCandidates as [$location, $project]) {
					$this->recordUnloaded($location, $project, VirionStatus::CONFLICT, $error->getMessage());
				}
				unset($requirementsByName[$nameKey]);
				continue;
			}

			foreach ($compatibleCandidates as [$location, $project]) {
				if ($project === $selected) {
					$selectedProjects[$nameKey] = [$location, $selected];
					$selectionActions[$nameKey][] = [
						'type' => 'selected',
						'location' => $location,
						'project' => $project,
					];
					continue;
				}
				$reason = $project->phar && $this->hasFolderCandidate($candidates)
					? 'a development folder for this virion name is preferred over a packaged PHAR'
					: ($this->satisfies($project, $nameRequirements)
						? "a higher compatible version {$selected->manifest->version} was selected"
						: 'it does not satisfy ' . $this->selector->describeRequirements($nameRequirements));
				$selectionActions[$nameKey][] = [
					'type' => 'skipped',
					'location' => $location,
					'project' => $project,
					'message' => "Skipped {$project->manifest->name} {$project->manifest->version} at {$location}: {$reason}.",
				];
			}
			$this->recordPreferredPhars($candidates);
			unset($requirementsByName[$nameKey]);
		}

		$preflight = $this->graphPreflight->check($selectedProjects, $this->registry->entries(), $this->registry->loaded());
		$this->graphReadyProjects = $preflight->readyProjects;
		foreach ($preflight->failures as $failure) {
			$this->processedLocations[$this->canonicalLocation($failure->location)] = true;
			$this->registry->record($failure);
		}
		foreach ($projectsByName as $nameKey => $_candidates) {
			foreach ($selectionActions[$nameKey] ?? [] as $action) {
				if ($action['type'] === 'selected') {
					$this->loadSelectedProject($nameKey, $selectedProjects);
					continue;
				}
				$this->recordUnloaded($action['location'], $action['project'], VirionStatus::SKIPPED, $action['message'] ?? '');
			}
		}

		foreach ($requirementsByName as $missingRequirements) {
			$first = $missingRequirements[0];
			$this->registry->record(new VirionRegistryEntry(
				$directory,
				null,
				VirionStatus::FAILED,
				"Required virion {$first->name} is missing. The project requests "
				. $this->selector->describeRequirements($missingRequirements)
				. ". Add a compatible package to {$directory}.",
			));
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

	private function inspectSelectedProject(VirionProject $project) : VirionSourceSymbols
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
			$this->assertCompatible($manifest);
			$symbols = $this->classScanner->scanSymbols($project);
			$classes = $symbols->classes;
			if ($classes === []) {
				throw new VirionException("Cannot load {$manifest->name}: no PHP classes were found in {$project->sourceRoot}.");
			}
			$projectClasses = [];
			foreach ($classes as $class) {
				$classKey = strtolower($class);
				if (isset($projectClasses[$classKey])) {
					throw new VirionException("Cannot load {$manifest->name}: class {$class} is declared more than once.");
				}
				$projectClasses[$classKey] = true;
				if (!str_starts_with($class, $manifest->antigen . '\\')) {
					throw new VirionException("Cannot load {$manifest->name}: class {$class} is outside antigen {$manifest->antigen}.");
				}
				$this->assertClassIsNotLoaded($class, $manifest->name);
				$existingClass = $this->registry->findByClass($class);
				if ($existingClass !== null) {
					throw new VirionException("Class {$class} is already provided by {$existingClass->requireManifest()->name}.");
				}
			}
			$this->assertPreflightSymbolsAvailable($project, $symbols);

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

	/** @param list<VirionRequirement> $requirements */
	private function satisfies(VirionProject $project, array $requirements) : bool
	{
		foreach ($requirements as $requirement) {
			if (!$requirement->matches($project->manifest->version)) {
				return false;
			}
		}

		return true;
	}

	private function recordUnloaded(
		string $location,
		VirionProject $project,
		VirionStatus $status,
		string $message,
	) : void {
		$locationKey = $this->canonicalLocation($location);
		if (isset($this->processedLocations[$locationKey])) {
			return;
		}
		$this->processedLocations[$locationKey] = true;
		$this->registry->record(new VirionRegistryEntry($location, $project->manifest, $status, $message));
	}

	/** @param list<array{string, VirionProject}> $candidates */
	private function hasFolderCandidate(array $candidates) : bool
	{
		foreach ($candidates as [, $candidate]) {
			if (!$candidate->phar) {
				return true;
			}
		}

		return false;
	}

	/** @param list<array{string, VirionProject}> $candidates
	 * @return list<array{string, VirionProject}>
	 */
	private function preferredCandidates(array $candidates) : array
	{
		if (!$this->hasFolderCandidate($candidates)) {
			return $candidates;
		}

		return array_values(array_filter(
			$candidates,
			static fn(array $candidate) : bool => !$candidate[1]->phar,
		));
	}

	/** @param list<array{string, VirionProject}> $candidates */
	private function recordPreferredPhars(array $candidates) : void
	{
		if (!$this->hasFolderCandidate($candidates)) {
			return;
		}
		foreach ($candidates as [$location, $project]) {
			if (!$project->phar) {
				continue;
			}
			$this->recordUnloaded(
				$location,
				$project,
				VirionStatus::SKIPPED,
				"Skipped {$project->manifest->name} {$project->manifest->version} at {$location}: a development folder for this virion name is preferred over a packaged PHAR.",
			);
		}
	}
}
