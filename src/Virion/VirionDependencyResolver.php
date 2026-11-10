<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use function array_intersect_key;
use function array_map;
use function array_pop;
use function array_values;
use function json_encode;
use function ksort;
use function str_starts_with;
use const JSON_THROW_ON_ERROR;

final class VirionDependencyResolver
{
	private const MAX_SELECTION_PASSES = 100;

	public function __construct(
		private readonly VirionProjectSelector $selector,
		private readonly PreparedVirionProvenance $provenance = new PreparedVirionProvenance(),
	) {}

	/**
	 * @param array<string, list<VirionProject>> $available
	 * @param list<VirionRequirement>            $roots
	 * @return list<VirionProject>
	 */
	public function resolve(array $available, array $roots) : array
	{
		$available = $this->normalizeAvailable($available);
		$selected = [];
		$states = [];
		for ($iteration = 0; $iteration < self::MAX_SELECTION_PASSES; ++$iteration) {
			$requirements = [];
			foreach ($roots as $requirement) {
				$requirements[VirionName::key($requirement->name)][] = $requirement;
			}
			foreach (array_intersect_key($selected, $this->reachable($selected, $roots)) as $project) {
				foreach ($project->manifest->requirements as $requirement) {
					$requirements[VirionName::key($requirement->name)][] = $requirement;
				}
			}
			$next = [];
			$failures = [];
			foreach ($requirements as $key => $constraints) {
				if (!isset($available[$key])) {
					$failures[$key] = new VirionException("Required virion {$constraints[0]->name} is missing. The project requests " . $this->selector->describeRequirements($constraints) . '. Add the transitive package to the selected virions directory.');
					continue;
				}
				try {
					$next[$key] = $this->selector->select($constraints[0]->name, $available[$key], $constraints);
				} catch (VirionException $error) {
					$failures[$key] = $error;
				}
			}
			$reachable = $this->reachable($next, $roots);
			$reachableFailure = null;
			foreach ($failures as $key => $failure) {
				if (isset($reachable[$key])) {
					$reachableFailure = $failure;
					break;
				}
			}
			$next = array_intersect_key($next, $reachable);
			if ($this->sameSelection($next, $selected)) {
				if ($reachableFailure !== null) {
					throw $reachableFailure;
				}
				foreach ($next as $key => $project) {
					foreach ($requirements[$key] as $constraint) {
						if ($constraint->composerPackageSha256 === null) {
							continue;
						}
						$this->provenance->verify($project, $constraint);
					}
				}
				return array_values($selected);
			}
			$locations = array_map(static fn(VirionProject $project) : string => $project->location, $next);
			ksort($locations);
			$state = json_encode($locations, JSON_THROW_ON_ERROR);
			if (isset($states[$state])) {
				if ($reachableFailure !== null) {
					throw $reachableFailure;
				}
				throw new VirionException('Virion dependency selection does not converge. Pin compatible local versions. DevTools does not backtrack through alternative dependency graphs.');
			}
			$states[$state] = true;
			$selected = $next;
		}
		throw new VirionException('Virion dependency graph exceeds ' . self::MAX_SELECTION_PASSES . ' selection passes. Reduce or pin the local dependency graph.');
	}

	/**
	 * Keep this resolver tolerant of callers that provide the historical raw-name map.
	 * Internally every graph key is prefixed so numeric-only names cannot become integer array keys.
	 *
	 * @param array<string, list<VirionProject>> $available
	 * @return array<string, list<VirionProject>>
	 */
	private function normalizeAvailable(array $available) : array
	{
		$normalized = [];
		foreach ($available as $key => $projects) {
			$project = $projects[0] ?? null;
			$normalizedKey = $project instanceof VirionProject
				? VirionName::key($project->manifest->name)
				: (str_starts_with((string) $key, 'virion:') ? (string) $key : VirionName::key((string) $key));
			$normalized[$normalizedKey] = $projects;
		}

		return $normalized;
	}

	/**
	 * @param array<string, VirionProject> $left
	 * @param array<string, VirionProject> $right
	 */
	private function sameSelection(array $left, array $right) : bool
	{
		ksort($left);
		ksort($right);

		return $left === $right;
	}

	/**
	 * @param array<string, VirionProject> $selected
	 * @param list<VirionRequirement>      $roots
	 * @return array<string, true>
	 */
	private function reachable(array $selected, array $roots) : array
	{
		$pending = array_map(static fn(VirionRequirement $requirement) : string => VirionName::key($requirement->name), $roots);
		$reachable = [];
		while ($pending !== []) {
			$key = array_pop($pending);
			if (isset($reachable[$key])) {
				continue;
			}
			$reachable[$key] = true;
			foreach (($selected[$key]->manifest->requirements ?? []) as $requirement) {
				$pending[] = VirionName::key($requirement->name);
			}
		}
		return $reachable;
	}
}
