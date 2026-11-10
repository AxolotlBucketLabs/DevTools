<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Loader;

use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use function array_merge;
use function realpath;
use function str_replace;
use function strtolower;
use const DIRECTORY_SEPARATOR;

final class PluginDependencyIndex
{
	/** @var array<string, list<VirionRequirement>> */
	private array $requirementsByProject = [];
	/** @var array<string, string> */
	private array $configurationFailures = [];

	/**
	 * @param array<string, list<VirionRequirement>> $requirementsByProject
	 * @param array<string, string>                  $configurationFailures
	 */
	public function __construct(
		array $requirementsByProject,
		array $configurationFailures,
		private readonly VirionRequirementChecker $checker,
	) {
		foreach ($requirementsByProject as $project => $requirements) {
			$this->requirementsByProject[self::projectKey($project)] = $requirements;
		}
		foreach ($configurationFailures as $project => $failure) {
			$this->configurationFailures[self::projectKey($project)] = $failure;
		}
	}

	/** @return list<VirionRequirement> */
	public function requirements() : array
	{
		$requirements = [];
		foreach ($this->requirementsByProject as $projectRequirements) {
			$requirements = array_merge($requirements, $projectRequirements);
		}
		return $requirements;
	}

	public function configurationFailure(string $project) : ?string
	{
		return $this->configurationFailures[self::projectKey($project)] ?? null;
	}

	public function dependencyFailure(string $project) : ?string
	{
		return $this->checker->failure($this->requirementsByProject[self::projectKey($project)] ?? []);
	}

	private static function projectKey(string $path) : string
	{
		$resolved = realpath($path) ?: $path;
		$normalized = str_replace('\\', '/', $resolved);

		return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
	}
}
