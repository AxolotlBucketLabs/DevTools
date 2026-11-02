<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function is_dir;
use function is_file;

use NhanAZ\DevTools\Support\Filesystem;

final class ProjectRoleDetector
{
    public function detect(string $projectRoot): ProjectRole
    {
        if (!is_dir($projectRoot) || Filesystem::isLink($projectRoot)) {
            throw new BuildException("Project directory does not exist or is a symbolic link: {$projectRoot}");
        }

        $plugin = is_file($projectRoot . DIRECTORY_SEPARATOR . 'plugin.yml');
        $virion = is_file($projectRoot . DIRECTORY_SEPARATOR . 'virion.yml');
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
