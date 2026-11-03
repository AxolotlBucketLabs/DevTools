<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function array_keys;
use function array_values;
use function basename;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use JsonException;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Virion\VirionException;
use NhanAZ\DevTools\Virion\VirionName;
use NhanAZ\DevTools\Virion\VirionRequirement;

use function preg_split;
use function str_replace;
use function strtolower;
use function trim;

final class BuildConfigResolver
{
    public function __construct(private readonly YamlReader $yamlReader) {}

    public function resolve(string $projectRoot, string $pluginName): BuildConfig
    {
        /** @var array<string, VirionRequirement> $virions */
        $virions = [];
        /** @var array<string, string> $includePaths */
        $includePaths = [];
        $importPoggitLibraries = true;

        $localPath = $projectRoot . DIRECTORY_SEPARATOR . 'devtools.yml';
        if (is_file($localPath)) {
            $local = $this->yamlReader->read($localPath);
            foreach (array_keys($local) as $key) {
                if (!in_array($key, ['virions', 'include-paths', 'import-poggit-libraries'], true)) {
                    throw new BuildException(
                        "Unknown key \"{$key}\" in {$localPath}. Supported keys are virions, include-paths and import-poggit-libraries.",
                    );
                }
            }
            if (isset($local['import-poggit-libraries'])) {
                if (!is_bool($local['import-poggit-libraries'])) {
                    throw new BuildException("import-poggit-libraries in {$localPath} must be true or false.");
                }
                $importPoggitLibraries = $local['import-poggit-libraries'];
            }
            $this->addVirionList($local['virions'] ?? [], $virions, 'virions', $localPath);
            $this->addPathList($local['include-paths'] ?? [], $includePaths, $localPath);
        }

        $composerPath = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerPath)) {
            $contents = file_get_contents($composerPath);
            if ($contents === false) {
                throw new BuildException("Cannot read {$composerPath}.");
            }
            try {
                $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new BuildException("Cannot parse {$composerPath}: {$error->getMessage()}", 0, $error);
            }
            if (is_array($composer)) {
                $extra = $composer['extra'] ?? null;
                $devtools = is_array($extra) ? ($extra['devtools'] ?? null) : null;
                $declaredVirions = is_array($devtools) ? ($devtools['virions'] ?? []) : [];
                $this->addVirionList($declaredVirions, $virions, 'extra.devtools.virions', $composerPath);
            }
        }

        $poggitPath = $projectRoot . DIRECTORY_SEPARATOR . '.poggit.yml';
        if ($importPoggitLibraries && is_file($poggitPath)) {
            $poggit = $this->yamlReader->read($poggitPath);
            $projects = $poggit['projects'] ?? [];
            if (is_array($projects)) {
                foreach ($projects as $name => $project) {
                    if (!is_string($name) || strtolower($name) !== strtolower($pluginName) || !is_array($project)) {
                        continue;
                    }
                    $libs = $project['libs'] ?? [];
                    if (!is_array($libs)) {
                        throw new BuildException("The libs declaration for {$pluginName} in {$poggitPath} must be a list.");
                    }
                    foreach ($libs as $lib) {
                        $source = is_array($lib) ? ($lib['src'] ?? null) : $lib;
                        if (!is_string($source) || trim($source) === '') {
                            throw new BuildException("A lib entry for {$pluginName} in {$poggitPath} has no src value.");
                        }
                        $constraint = is_array($lib) ? ($lib['version'] ?? '*') : '*';
                        if (!is_string($constraint) || trim($constraint) === '') {
                            throw new BuildException("A lib entry for {$pluginName} in {$poggitPath} has an invalid version constraint.");
                        }
                        $sourceParts = preg_split('/@/', $source, 2);
                        if ($sourceParts !== false) {
                            $source = $sourceParts[0];
                        }
                        $virion = basename(str_replace('\\', '/', $source));
                        $this->addRequirement(
                            new VirionRequirement($virion, $constraint, "{$poggitPath}:projects.{$pluginName}.libs"),
                            $virions,
                        );
                    }
                }
            }
        }

        $selfPackaging = strtolower($pluginName) === 'devtools'
            && in_array('vendor/nikic/php-parser/lib', $includePaths, true)
            && in_array('vendor/nikic/php-parser/LICENSE', $includePaths, true);
        if (!$selfPackaging) {
            foreach ((new ComposerVirionPlan())->requirements($projectRoot) as $requirement) {
                $this->addRequirement($requirement, $virions);
            }
        }

        return new BuildConfig(array_values($virions), array_values($includePaths));
    }

    /**
     * @param array<string, VirionRequirement> $target
     */
    private function addVirionList(mixed $value, array &$target, string $field, string $path): void
    {
        if ($value === null || $value === []) {
            return;
        }
        if (!is_array($value)) {
            throw new BuildException("{$field} in {$path} must be a list of virion names.");
        }
        foreach ($value as $item) {
            $name = $item;
            $constraint = '*';
            if (is_array($item)) {
                foreach (array_keys($item) as $key) {
                    if (!is_string($key) || !in_array($key, ['name', 'version'], true)) {
                        throw new BuildException("{$field} in {$path} contains an unknown virion requirement key.");
                    }
                }
                $name = $item['name'] ?? null;
                $constraint = $item['version'] ?? '*';
            }
            if (!is_string($name) || trim($name) === '' || !is_string($constraint) || trim($constraint) === '') {
                throw new BuildException("{$field} in {$path} must contain names or {name, version} maps with non-empty strings.");
            }
            try {
                $requirement = new VirionRequirement($name, $constraint, "{$path}:{$field}");
            } catch (VirionException $error) {
                throw new BuildException($error->getMessage(), 0, $error);
            }
            $this->addRequirement($requirement, $target);
        }
    }

    /** @param array<string, VirionRequirement> $target */
    private function addRequirement(VirionRequirement $requirement, array &$target): void
    {
        $key = VirionName::key($requirement->name);
        $existing = $target[$key] ?? null;
        if ($existing === null) {
            $target[$key] = $requirement;

            return;
        }
        $existingExpression = $existing->constraint->expression;
        $newExpression = $requirement->constraint->expression;
        if ($requirement->composerPackageSha256 !== null) {
            $existing = new VirionRequirement($existing->name, $existingExpression, $existing->source, $requirement->composerPackageSha256);
            $target[$key] = $existing;
        }
        if ($existingExpression === $newExpression || $newExpression === '*') {
            return;
        }
        if ($existingExpression === '*') {
            $target[$key] = $requirement;

            return;
        }
        try {
            $target[$key] = new VirionRequirement(
                $requirement->name,
                $existingExpression . ' ' . $newExpression,
                $existing->source . ', ' . $requirement->source,
                $requirement->composerPackageSha256 ?? $existing->composerPackageSha256,
            );
        } catch (VirionException $error) {
            throw new BuildException($error->getMessage(), 0, $error);
        }
    }

    /** @param array<string,string> $target */
    private function addPathList(mixed $value, array &$target, string $path): void
    {
        if ($value === null || $value === []) {
            return;
        }
        if (!is_array($value)) {
            throw new BuildException("include-paths in {$path} must be a list of relative paths.");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new BuildException("include-paths in {$path} contains a non-string value.");
            }
            try {
                $normalized = Path::normalizeRelative($item);
            } catch (\RuntimeException $error) {
                throw new BuildException("Invalid include path in {$path}: {$error->getMessage()}", 0, $error);
            }
            if (Path::isSensitive($normalized)) {
                throw new BuildException(
                    "Refusing sensitive include path {$normalized} in {$path}. Remove credentials and private keys from build inputs.",
                );
            }
            if (Path::isHiddenOrTemporary($normalized)) {
                throw new BuildException(
                    "Refusing hidden or temporary include path {$normalized} in {$path}. Remove hidden and temporary files from build inputs.",
                );
            }
            $key = strtolower($normalized);
            if (isset($target[$key]) && $target[$key] !== $normalized) {
                throw new BuildException(
                    "include-paths in {$path} contains case-conflicting paths {$target[$key]} and {$normalized}.",
                );
            }
            $target[$key] = $normalized;
        }
    }
}
