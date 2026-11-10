<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Validation\PluginProjectValidator;
use Throwable;
use const DIRECTORY_SEPARATOR;

final class ProjectDoctor
{
	public function __construct(
		private readonly PluginProjectValidator $validator,
		private readonly PluginManifestReader $manifestReader,
		private readonly BuildConfigResolver $configResolver,
		private readonly VirionResolver $resolver,
	) {}

	/** The explicit manifest path preserves the caller's path spelling in diagnostics. */
	public function check(string $project, string $virionsDirectory, ?string $manifestPath = null) : ProjectCheckResult
	{
		$validation = $this->validator->validate($project);
		if ($validation->hasErrors()) {
			return new ProjectCheckResult($validation);
		}

		$manifest = null;
		$dependencyStatus = 'not_run';
		try {
			$manifest = $this->manifestReader->read($manifestPath ?? $project . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST);
			$dependencyStatus = 'failed';
			$config = $this->configResolver->resolve($project, $manifest->description->getName());
			$virions = $this->resolver->resolve($config->virions, $virionsDirectory);

			return new ProjectCheckResult($validation, $manifest, $virions, 'passed');
		} catch (Throwable $error) {
			return new ProjectCheckResult($validation, $manifest, dependencyStatus: $dependencyStatus, error: $error);
		}
	}
}
