<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use Closure;
use function array_filter;
use function array_values;
use function ksort;
use const SORT_STRING;

final class VirionSelectionPlanner
{
	/** @param Closure(VirionManifest): void $assertCompatible */
	public function __construct(
		private readonly VirionProjectSelector $selector,
		private readonly Closure $assertCompatible,
	) {}

	/**
	 * @param array<string, list<array{string, VirionProject}>> $projectsByName
	 * @param list<VirionRequirement>                           $requirements
	 * @param array<string, string>                             $invalidFolderFailures
	 */
	public function plan(
		array $projectsByName,
		array $requirements,
		string $directory,
		array $invalidFolderFailures = [],
		bool $hasRequirementAliases = false,
	) : VirionSelectionPlan {
		$requirementsByName = [];
		$hasComposerRequirements = false;
		foreach ($requirements as $requirement) {
			$requirementsByName[VirionName::key($requirement->name)][] = $requirement;
			$hasComposerRequirements = $hasComposerRequirements || $requirement->composerPackageSha256 !== null;
		}
		$diagnostics = [];
		foreach ($invalidFolderFailures as $nameKey => $errorMessage) {
			if (!isset($projectsByName[$nameKey]) || $this->hasFolderCandidate($projectsByName[$nameKey])) {
				continue;
			}
			foreach ($projectsByName[$nameKey] as [$location, $candidate]) {
				$diagnostics[] = new VirionRegistryEntry(
					$location,
					$candidate->manifest,
					VirionStatus::SKIPPED,
					"Version {$candidate->manifest->version} at {$location}: a development folder with the same name could not be loaded. {$errorMessage}",
				);
			}
			unset($projectsByName[$nameKey], $requirementsByName[$nameKey]);
		}
		ksort($projectsByName, SORT_STRING);
		$hasTransitive = false;
		$available = [];
		foreach ($projectsByName as $nameKey => $candidates) {
			foreach ($this->preferredCandidates($candidates) as [, $project]) {
				$hasTransitive = $hasTransitive || $project->manifest->requirements !== [];
				try {
					($this->assertCompatible)($project->manifest);
					$available[$nameKey][] = $project;
				} catch (VirionException) {
				}
			}
		}
		if ($hasTransitive || $hasComposerRequirements || $hasRequirementAliases) {
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
					$diagnostics[] = new VirionRegistryEntry($directory, null, VirionStatus::FAILED, $error->getMessage());
				}
				foreach ($projectsByName as $candidates) {
					foreach ($candidates as [$location, $project]) {
						$diagnostics[] = new VirionRegistryEntry($location, $project->manifest, VirionStatus::CONFLICT, $error->getMessage());
					}
				}
				return new VirionSelectionPlan([], [], $diagnostics, [], true);
			}
		}
		$selectedProjects = [];
		$actions = [];
		foreach ($projectsByName as $nameKey => $candidates) {
			$compatibleCandidates = [];
			foreach ($this->preferredCandidates($candidates) as [$location, $project]) {
				try {
					($this->assertCompatible)($project->manifest);
					$compatibleCandidates[] = [$location, $project];
				} catch (VirionException $error) {
					$diagnostics[] = new VirionRegistryEntry($location, $project->manifest, VirionStatus::FAILED, $error->getMessage());
				}
			}
			if ($compatibleCandidates === []) {
				foreach ($this->preferredPharDiagnostics($candidates) as $entry) {
					$diagnostics[] = $entry;
				}
				unset($requirementsByName[$nameKey]);
				continue;
			}
			$projects = [];
			foreach ($compatibleCandidates as [, $project]) {
				$projects[] = $project;
			}
			$name = $projects[0]->manifest->name;
			$nameRequirements = $requirementsByName[$nameKey] ?? [];
			try {
				$selected = $this->selector->select($name, $projects, $nameRequirements);
			} catch (VirionException $error) {
				foreach ($compatibleCandidates as [$location, $project]) {
					$diagnostics[] = new VirionRegistryEntry($location, $project->manifest, VirionStatus::CONFLICT, $error->getMessage());
				}
				unset($requirementsByName[$nameKey]);
				continue;
			}
			foreach ($compatibleCandidates as [$location, $project]) {
				if ($project === $selected) {
					$selectedProjects[$nameKey] = [$location, $selected];
					$actions[] = ['type' => 'selected', 'location' => $location, 'project' => $project];
					continue;
				}
				$reason = $project->phar && $this->hasFolderCandidate($candidates)
					? 'a development folder for this virion name is preferred over a packaged PHAR'
					: ($this->satisfies($project, $nameRequirements)
						? "a higher compatible version {$selected->manifest->version} was selected"
						: 'it does not satisfy ' . $this->selector->describeRequirements($nameRequirements));
				$actions[] = [
					'type' => 'skipped',
					'location' => $location,
					'project' => $project,
					'message' => "Version {$project->manifest->version} at {$location}: {$reason}.",
				];
			}
			foreach ($this->preferredPharDiagnostics($candidates) as $entry) {
				$diagnostics[] = $entry;
			}
			unset($requirementsByName[$nameKey]);
		}
		$missing = [];
		foreach ($requirementsByName as $missingRequirements) {
			$first = $missingRequirements[0];
			$missing[] = new VirionRegistryEntry(
				$directory,
				null,
				VirionStatus::FAILED,
				"Required virion {$first->name} is missing. The project requests "
				. $this->selector->describeRequirements($missingRequirements)
				. ". Add a compatible package to {$directory}.",
			);
		}
		return new VirionSelectionPlan($selectedProjects, $actions, $diagnostics, $missing);
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

	/**
	 * @param list<array{string, VirionProject}> $candidates
	 * @return list<array{string, VirionProject}>
	 */
	private function preferredCandidates(array $candidates) : array
	{
		return $this->hasFolderCandidate($candidates)
			? array_values(array_filter($candidates, static fn(array $candidate) : bool => !$candidate[1]->phar))
			: $candidates;
	}

	/**
	 * @param list<array{string, VirionProject}> $candidates
	 * @return list<VirionRegistryEntry>
	 */
	private function preferredPharDiagnostics(array $candidates) : array
	{
		$diagnostics = [];
		if (!$this->hasFolderCandidate($candidates)) {
			return $diagnostics;
		}
		foreach ($candidates as [$location, $project]) {
			if ($project->phar) {
				$diagnostics[] = new VirionRegistryEntry(
					$location,
					$project->manifest,
					VirionStatus::SKIPPED,
					"Version {$project->manifest->version} at {$location}: a development folder for this virion name is preferred over a packaged PHAR.",
				);
			}
		}
		return $diagnostics;
	}
}
