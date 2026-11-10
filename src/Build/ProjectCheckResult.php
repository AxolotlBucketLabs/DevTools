<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Validation\PluginManifest;
use AxolotlBucketLabs\DevTools\Validation\ValidationResult;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use Throwable;

final class ProjectCheckResult
{
	/**
	 * @param list<VirionProject>         $virions
	 * @param 'not_run'|'failed'|'passed' $dependencyStatus
	 */
	public function __construct(
		public readonly ValidationResult $validation,
		public readonly ?PluginManifest $manifest = null,
		public readonly array $virions = [],
		public readonly string $dependencyStatus = 'not_run',
		public readonly ?Throwable $error = null,
	) {}

	public function requireManifest() : PluginManifest
	{
		if ($this->manifest === null) {
			throw new \LogicException('A successful project check must have a plugin manifest.');
		}

		return $this->manifest;
	}
}
