<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use Closure;
use Throwable;
use function array_fill_keys;
use function array_keys;
use function array_pop;
use function count;
use function in_array;
use function ltrim;
use function min;
use function realpath;
use function str_replace;
use function str_starts_with;
use function strtolower;

final class VirionGraphPreflight
{
	/** @var array<string, 'checking'|'ready'|'failed'> */
	private array $states = [];

	/** @var array<string, VirionSourceSymbols> */
	private array $symbols = [];

	/** @param Closure(VirionProject): VirionSourceSymbols $inspect */
	public function __construct(private readonly Closure $inspect) {}

	/**
	 * Readiness and inspected symbols persist across calls. Failures are returned in registry order.
	 *
	 * @param array<string, array{string, VirionProject}> $selectedProjects
	 * @param list<VirionRegistryEntry>                   $entries
	 * @param list<VirionRegistryEntry>                   $loadedEntries
	 */
	public function check(array $selectedProjects, array $entries, array $loadedEntries) : VirionGraphPreflightResult
	{
		$failures = [];
		$this->preflightSelectedDependencyCycles($selectedProjects, $entries, $loadedEntries, $failures);
		$readyProjects = [];
		foreach (array_keys($selectedProjects) as $key) {
			if (($this->states[$key] ?? null) === 'ready') {
				$readyProjects[$key] = true;
			}
		}

		return new VirionGraphPreflightResult($readyProjects, $failures);
	}

	/**
	 * @param array<string, array{string, VirionProject}> $selectedProjects
	 * @param list<VirionRegistryEntry>                   $entries
	 * @param list<VirionRegistryEntry>                   $failures
	 */
	private function preflightSelectedProject(string $nameKey, array $selectedProjects, array &$entries, array &$failures) : bool
	{
		$state = $this->states[$nameKey] ?? null;
		if ($state === 'ready') {
			return true;
		}
		if ($state === 'failed') {
			return false;
		}
		if ($state === 'checking') {
			return true;
		}
		if (!isset($selectedProjects[$nameKey])) {
			$this->states[$nameKey] = 'failed';

			return false;
		}

		$this->states[$nameKey] = 'checking';
		[$location, $project] = $selectedProjects[$nameKey];
		foreach ($project->manifest->requirements as $requirement) {
			$dependencyKey = VirionName::key($requirement->name);
			if (!isset($selectedProjects[$dependencyKey])) {
				$this->recordFailure(
					$entries,
					$failures,
					$location,
					$project,
					"Required virion {$requirement->name} was not selected for {$project->manifest->name}.",
				);
				$this->states[$nameKey] = 'failed';

				return false;
			}
			if ($this->preflightSelectedProject($dependencyKey, $selectedProjects, $entries, $failures)) {
				continue;
			}
			$dependency = $this->entryForLocation($entries, $selectedProjects[$dependencyKey][0]);
			$detail = $dependency === null ? 'The dependency failed before it could be diagnosed.' : $dependency->message;
			$this->recordFailure(
				$entries,
				$failures,
				$location,
				$project,
				"Cannot load {$project->manifest->name}: dependency {$requirement->name} failed. {$detail}",
			);
			$this->states[$nameKey] = 'failed';

			return false;
		}

		try {
			$this->symbols[$nameKey] = ($this->inspect)($project);
		} catch (Throwable $error) {
			$this->recordFailure($entries, $failures, $location, $project, $error->getMessage());
			$this->states[$nameKey] = 'failed';

			return false;
		}

		$this->states[$nameKey] = 'ready';

		return true;
	}

	/**
	 * @param array<string, array{string, VirionProject}> $selectedProjects
	 * @param list<VirionRegistryEntry>                   $entries
	 * @param list<VirionRegistryEntry>                   $loadedEntries
	 * @param list<VirionRegistryEntry>                   $failures
	 */
	private function preflightSelectedDependencyCycles(array $selectedProjects, array &$entries, array $loadedEntries, array &$failures) : void
	{
		foreach (array_keys($selectedProjects) as $nameKey) {
			$this->preflightSelectedProject($nameKey, $selectedProjects, $entries, $failures);
		}

		do {
			$changed = false;
			foreach ($selectedProjects as $nameKey => [$location, $project]) {
				if (($this->states[$nameKey] ?? null) !== 'ready') {
					continue;
				}
				foreach ($project->manifest->requirements as $requirement) {
					$dependencyKey = VirionName::key($requirement->name);
					if (($this->states[$dependencyKey] ?? null) !== 'failed') {
						continue;
					}
					$dependency = $this->entryForLocation($entries, $selectedProjects[$dependencyKey][0] ?? '');
					$detail = $dependency === null ? 'The dependency failed before it could be diagnosed.' : $dependency->message;
					$this->recordFailure(
						$entries,
						$failures,
						$location,
						$project,
						"Cannot load {$project->manifest->name}: dependency {$requirement->name} failed. {$detail}",
					);
					$this->states[$nameKey] = 'failed';
					$changed = true;
					break;
				}
			}
		} while ($changed);

		// Keep the initial ready projects visible while cycle conflicts change their states.
		$readyKeys = [];
		foreach (array_keys($selectedProjects) as $key) {
			if (($this->states[$key] ?? null) === 'ready') {
				$readyKeys[] = $key;
			}
		}
		foreach ($this->stronglyConnectedComponents($selectedProjects) as $component) {
			$isCycle = count($component) > 1;
			if (!$isCycle) {
				$cycleKey = $component[0] ?? null;
				if ($cycleKey === null) {
					continue;
				}
				foreach ($selectedProjects[$cycleKey][1]->manifest->requirements as $requirement) {
					if (VirionName::key($requirement->name) === $cycleKey) {
						$isCycle = true;
						break;
					}
				}
			}
			if (!$isCycle) {
				continue;
			}

			$conflict = null;
			$otherName = null;
			$componentCount = count($component);
			for ($left = 0; $left < $componentCount && $conflict === null; ++$left) {
				$leftKey = $component[$left];
				for ($right = $left + 1; $right < $componentCount; ++$right) {
					$rightKey = $component[$right];
					$conflict = $this->selectedProjectConflict($leftKey, $rightKey, $selectedProjects);
					if ($conflict !== null) {
						$otherName = $selectedProjects[$rightKey][1]->manifest->name;
						break;
					}
				}
			}
			if ($conflict === null) {
				foreach ($readyKeys as $externalKey) {
					if (in_array($externalKey, $component, true)) {
						continue;
					}
					foreach ($component as $cycleKey) {
						$conflict = $this->selectedProjectConflict($cycleKey, $externalKey, $selectedProjects);
						if ($conflict !== null) {
							$otherName = $selectedProjects[$externalKey][1]->manifest->name;
							break 2;
						}
					}
				}
			}
			if ($conflict === null) {
				foreach ($loadedEntries as $loaded) {
					$loadedManifest = $loaded->requireManifest();
					foreach ($component as $cycleKey) {
						$cycleProject = $selectedProjects[$cycleKey][1];
						$conflict = $this->describeProjectConflict(
							$cycleProject->manifest,
							$this->symbols[$cycleKey] ?? new VirionSourceSymbols([], [], [], []),
							$loadedManifest,
							new VirionSourceSymbols($loaded->classes, $loaded->functions, $loaded->constants, $loaded->functions),
						);
						if ($conflict !== null) {
							$otherName = $loadedManifest->name;
							break 2;
						}
					}
				}
			}
			if ($conflict === null) {
				continue;
			}

			foreach ($component as $cycleKey) {
				[$cycleLocation, $cycleProject] = $selectedProjects[$cycleKey];
				$this->recordFailure(
					$entries,
					$failures,
					$cycleLocation,
					$cycleProject,
					"Cannot load {$cycleProject->manifest->name}: dependency cycle with {$otherName} has a {$conflict}.",
				);
				$this->states[$cycleKey] = 'failed';
			}
		}
	}

	/**
	 * @param array<string, array{string, VirionProject}> $selectedProjects
	 * @return list<list<string>>
	 */
	private function stronglyConnectedComponents(array $selectedProjects) : array
	{
		$ready = [];
		foreach (array_keys($selectedProjects) as $key) {
			if (($this->states[$key] ?? null) === 'ready') {
				$ready[$key] = true;
			}
		}

		$index = 0;
		/** @var array<string, int> $indices */
		$indices = [];
		/** @var array<string, int> $lowLinks */
		$lowLinks = [];
		/** @var list<string> $stack */
		$stack = [];
		/** @var array<string, true> $onStack */
		$onStack = [];
		/** @var list<list<string>> $components */
		$components = [];
		/** @var Closure(string): void $visit */
		$visit = function (string $node) use (&$visit, &$index, &$indices, &$lowLinks, &$stack, &$onStack, &$components, $ready, $selectedProjects) : void {
			$indices[$node] = $index;
			$lowLinks[$node] = $index;
			++$index;
			$stack[] = $node;
			$onStack[$node] = true;

			foreach ($selectedProjects[$node][1]->manifest->requirements as $requirement) {
				$dependency = VirionName::key($requirement->name);
				if (!isset($ready[$dependency])) {
					continue;
				}
				if (!isset($indices[$dependency])) {
					$visit($dependency);
					$lowLinks[$node] = min($lowLinks[$node], $lowLinks[$dependency]);
				} elseif (isset($onStack[$dependency])) {
					$lowLinks[$node] = min($lowLinks[$node], $indices[$dependency]);
				}
			}

			if ($lowLinks[$node] !== $indices[$node]) {
				return;
			}

			$component = [];
			do {
				$member = array_pop($stack);
				if ($member === null) {
					break;
				}
				unset($onStack[$member]);
				$component[] = $member;
			} while ($member !== $node);
			$components[] = $component;
		};

		foreach (array_keys($ready) as $key) {
			if (!isset($indices[$key])) {
				$visit($key);
			}
		}

		return $components;
	}

	/** @param array<string, array{string, VirionProject}> $selectedProjects */
	private function selectedProjectConflict(string $leftKey, string $rightKey, array $selectedProjects) : ?string
	{
		return $this->describeProjectConflict(
			$selectedProjects[$leftKey][1]->manifest,
			$this->symbols[$leftKey] ?? new VirionSourceSymbols([], [], [], []),
			$selectedProjects[$rightKey][1]->manifest,
			$this->symbols[$rightKey] ?? new VirionSourceSymbols([], [], [], []),
		);
	}

	private function describeProjectConflict(
		VirionManifest $left,
		VirionSourceSymbols $leftSymbols,
		VirionManifest $right,
		VirionSourceSymbols $rightSymbols,
	) : ?string {
		if ($this->isNamespaceMember($left->antigen, $right->antigen)
			|| $this->isNamespaceMember($right->antigen, $left->antigen)) {
			return "antigen {$left->antigen} conflicts with {$right->antigen}";
		}

		$rightClassMap = [];
		foreach ($rightSymbols->classes as $class) {
			$rightClassMap[strtolower($class)] = $class;
		}
		foreach ($leftSymbols->classes as $class) {
			if (isset($rightClassMap[strtolower($class)])) {
				return "class {$class} conflicts with {$rightClassMap[strtolower($class)]}";
			}
		}

		$rightFunctionMap = [];
		foreach ($rightSymbols->unconditionalFunctions as $function) {
			$rightFunctionMap[strtolower($function)] = $function;
		}
		foreach ($leftSymbols->unconditionalFunctions as $function) {
			if (isset($rightFunctionMap[strtolower($function)])) {
				return "function {$function} conflicts with {$rightFunctionMap[strtolower($function)]}";
			}
		}

		$rightConstantMap = array_fill_keys($rightSymbols->constants, true);
		foreach ($leftSymbols->constants as $constant) {
			if (isset($rightConstantMap[$constant])) {
				return "constant {$constant} conflicts with {$constant}";
			}
		}

		return null;
	}

	/**
	 * @param list<VirionRegistryEntry> $entries
	 * @param list<VirionRegistryEntry> $failures
	 */
	private function recordFailure(array &$entries, array &$failures, string $location, VirionProject $project, string $message) : void
	{
		$entry = new VirionRegistryEntry($location, $project->manifest, VirionStatus::FAILED, $message);
		$entries[] = $entry;
		$failures[] = $entry;
	}

	/** @param list<VirionRegistryEntry> $entries */
	private function entryForLocation(array $entries, string $location) : ?VirionRegistryEntry
	{
		$canonical = $this->canonicalLocation($location);
		foreach ($entries as $entry) {
			if ($this->canonicalLocation($entry->location) === $canonical) {
				return $entry;
			}
		}

		return null;
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
}
