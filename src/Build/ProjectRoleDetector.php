<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use function is_dir;
use function is_file;
use const DIRECTORY_SEPARATOR;

final class ProjectRoleDetector
{
	public function detect(string $projectRoot) : ProjectRole
	{
		if (!is_dir($projectRoot) || Filesystem::isLink($projectRoot)) {
			throw new BuildException("Project directory does not exist or is a symbolic link: {$projectRoot}");
		}

		$plugin = is_file($projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST);
		$virion = is_file($projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::VIRION_MANIFEST);
		if ($plugin && $virion) {
			return ProjectRole::DUAL;
		}
		if ($plugin) {
			return ProjectRole::PLUGIN;
		}
		if ($virion) {
			return ProjectRole::VIRION;
		}

		throw new BuildException("Project {$projectRoot} has neither plugin.yml nor virion.yml.");
	}
}
