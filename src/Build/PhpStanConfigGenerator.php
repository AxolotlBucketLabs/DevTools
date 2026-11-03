<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function array_keys;
use function implode;
use function is_dir;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Virion\VirionProject;

use function preg_match;
use function realpath;
use function str_replace;
use function trim;

final class PhpStanConfigGenerator
{
    /**
     * @param list<VirionProject> $virions
     * @param list<string> $additionalScanDirectories
     */
    public function generate(
        string $projectRoot,
        string $serverRoot,
        array $virions,
        string $level,
        string $temporaryDirectory,
        array $additionalScanDirectories = [],
    ): string {
        $level = $this->normalizeLevel($level);
        $projectRoot = $this->requireDirectory($projectRoot, 'plugin project');
        $serverRoot = $this->requireDirectory($serverRoot, 'server source');
        $projectSource = $this->requireDirectory($projectRoot . DIRECTORY_SEPARATOR . 'src', 'plugin source');
        $serverSource = $this->requireDirectory($serverRoot . DIRECTORY_SEPARATOR . 'src', 'server source');

        $scanDirectories = [$this->portable($serverSource) => $serverSource];
        foreach (['generated', 'vendor'] as $optional) {
            $path = $serverRoot . DIRECTORY_SEPARATOR . $optional;
            if (Filesystem::isLink($path)) {
                throw new BuildException("Refusing symbolic-link server {$optional} directory: {$path}");
            }
            if (is_dir($path)) {
                $real = realpath($path);
                if ($real === false) {
                    throw new BuildException("Cannot resolve server {$optional} directory: {$path}");
                }
                $scanDirectories[$this->portable($real)] = $real;
            }
        }

        foreach ($virions as $virion) {
            if (Filesystem::isLink($virion->sourceRoot) || !is_dir($virion->sourceRoot)) {
                throw new BuildException(
                    "Cannot analyze virion {$virion->manifest->name}: source directory is missing or is a symbolic link at {$virion->sourceRoot}.",
                );
            }
            $scanDirectories[$this->portable($virion->sourceRoot)] = $virion->sourceRoot;
        }

        foreach ($additionalScanDirectories as $directory) {
            $real = $this->requireDirectory($directory, 'additional PHPStan scan');
            $scanDirectories[$this->portable($real)] = $real;
        }

        $temporaryDirectory = trim($temporaryDirectory);
        if ($temporaryDirectory === '') {
            throw new BuildException('PHPStan temporary directory may not be empty.');
        }

        $lines = [
            'parameters:',
            "    level: {$level}",
            '    paths:',
            '        - ' . $this->quote($projectSource),
            '    scanDirectories:',
        ];
        foreach (array_keys($scanDirectories) as $directory) {
            $lines[] = '        - ' . $this->quote($directory);
        }
        $lines[] = '    tmpDir: ' . $this->quote($temporaryDirectory);
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function normalizeLevel(string $level): string
    {
        $level = trim($level);
        if ($level === 'max') {
            return $level;
        }
        if (preg_match('/^(?:[0-9]|10)$/D', $level) !== 1) {
            throw new BuildException(
                "Invalid PHPStan level \"{$level}\". Use off, a number from 0 through 10, or max. Omit phpstan or use off to disable analysis.",
            );
        }

        return $level;
    }

    private function requireDirectory(string $path, string $description): string
    {
        if (Filesystem::isLink($path)) {
            throw new BuildException("Refusing symbolic-link {$description} directory: {$path}");
        }
        if (!is_dir($path)) {
            throw new BuildException("Cannot run PHPStan: {$description} directory is missing at {$path}.");
        }
        $real = realpath($path);
        if ($real === false) {
            throw new BuildException("Cannot resolve {$description} directory: {$path}");
        }

        return $real;
    }

    private function quote(string $path): string
    {
        return json_encode(str_replace('%', '%%', $this->portable($path)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function portable(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
