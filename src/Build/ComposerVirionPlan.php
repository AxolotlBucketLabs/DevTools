<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use AxolotlBucketLabs\DevTools\Virion\VirionVersionConstraint;

final class ComposerVirionPlan
{
    /** @return array<string, array<string, mixed>> */
    public function packages(string $projectRoot): array
    {
        $path = $projectRoot . '/composer.json';
        if (!is_file($path)) {
            return [];
        }
        $composer = $this->readJson($path);
        $rootAutoload = $composer['autoload'] ?? [];
        if (!is_array($rootAutoload) || array_diff(array_keys($rootAutoload), ['psr-4', 'psr-0']) !== []) {
            throw new BuildException('Root Composer autoload.files, classmap and other runtime bootstrap modes are unsupported. Axolotl-PM loads plugin classes from plugin.yml and src/.');
        }
        $sourcePrefix = $rootAutoload === [] ? '' : (new PluginManifestReader(new YamlReader()))->read($projectRoot . '/plugin.yml')->description->getSrcNamespacePrefix();
        $sourcePrefix = trim($sourcePrefix, '\\');
        foreach ($rootAutoload as $kind => $mapping) {
            if (!is_array($mapping)) {
                throw new BuildException('Root Composer autoload mappings must name source directories under src/.');
            }
            foreach ($mapping as $namespace => $source) {
                if (!is_string($source)) {
                    throw new BuildException('Root Composer autoload mappings must each use one source directory under src/. Multiple source roots are unsupported.');
                }
                $normalized = Path::normalizeRelative($source);
                if ($normalized !== 'src' && !str_starts_with($normalized, 'src/')) {
                    throw new BuildException("Root Composer autoload path {$source} is outside src/. Move plugin source under src/ and use the namespace layout declared by plugin.yml.");
                }
                if (!is_string($namespace) || ($namespace !== '' && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/D', $namespace) !== 1)) {
                    throw new BuildException('Root Composer mappings must use namespace prefixes ending in a backslash.');
                }
                $namespace = rtrim($namespace, '\\');
                if ($sourcePrefix !== '' && $namespace !== $sourcePrefix && !str_starts_with($namespace, $sourcePrefix . '\\')) {
                    throw new BuildException("Root Composer namespace {$namespace} is outside plugin.yml src-namespace-prefix {$sourcePrefix} and would not autoload on Axolotl-PM.");
                }
                $suffix = $sourcePrefix === '' ? $namespace : ltrim(substr($namespace, strlen($sourcePrefix)), '\\');
                $expected = 'src' . ($suffix === '' ? '' : '/' . str_replace('\\', '/', $suffix));
                $effective = $normalized . ($kind === 'psr-0' && $namespace !== '' ? '/' . str_replace('\\', '/', $namespace) : '');
                if ($effective !== $expected) {
                    throw new BuildException("Root Composer namespace {$namespace} resolves to {$effective}, but Axolotl-PM expects {$expected} from plugin.yml. Align the mapping and source layout before building.");
                }
            }
        }
        $roots = $this->dependencyNames($composer['require'] ?? [], $path);
        if ($roots === []) {
            return [];
        }
        $lockPath = $projectRoot . '/composer.lock';
        $lock = $this->readJson($lockPath);
        $content = array_intersect_key($composer, array_flip(['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
        $config = $composer['config'] ?? null;
        if (is_array($config)) {
            $relevantConfig = [];
            foreach (['platform', 'policy'] as $key) {
                if (isset($config[$key])) {
                    $relevantConfig[$key] = $config[$key];
                }
            }
            if ($relevantConfig !== []) {
                $content['config'] = $relevantConfig;
            }
        }
        ksort($content);
        if (($lock['content-hash'] ?? null) !== md5(json_encode($content, JSON_THROW_ON_ERROR))) {
            throw new BuildException('composer.lock is stale or lacks its Composer content-hash. Run composer update explicitly and review the lock change before preparation or building.');
        }
        $locked = [];
        $runtimePackages = $lock['packages'] ?? [];
        if (!is_array($runtimePackages)) {
            throw new BuildException("Invalid packages in {$lockPath}.");
        }
        foreach ($runtimePackages as $package) {
            $package = $this->object($package, $lockPath);
            if (!is_string($package['name'] ?? null)) {
                throw new BuildException("Invalid runtime package in {$lockPath}.");
            }
            $locked[$package['name']] = $package;
        }
        $result = [];
        $pending = $roots;
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($result[$name])) {
                continue;
            }
            if ($name === 'pocketmine/pocketmine-mp') {
                $server = $locked['axolotl-pm/pocketmine-mp'] ?? null;
                $replacements = is_array($server) ? ($server['replace'] ?? null) : null;
                if (is_array($replacements) && isset($replacements[$name])) {
                    continue;
                }
            }
            $package = $locked[$name] ?? null;
            if ($package === null) {
                throw new BuildException("Runtime dependency {$name} is absent from composer.lock packages. Run Composer install or update explicitly. Packages in require-dev, virtual packages and replacements are not bundled.");
            }
            $extra = $package['extra'] ?? null;
            $virion = is_array($extra) ? ($extra['virion'] ?? null) : null;
            if (!is_array($virion) || !in_array($virion['spec'] ?? null, ['3.0', '3.1'], true)) {
                throw new BuildException("Composer runtime dependency {$name} must declare extra.virion.spec 3.0 or 3.1. General Composer packages are not automatically bundled.");
            }
            if (isset($virion['shared-namespace-root'])) {
                throw new BuildException("Composer virion {$name} uses shared-namespace-root, which cannot be bundled as a private dependency. Remove shared public types or keep using a tool supporting that contract.");
            }
            $antigen = $virion['namespace-root'] ?? null;
            if (!is_string($antigen) || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $antigen) !== 1) {
                throw new BuildException("Composer virion {$name} has an invalid extra.virion.namespace-root.");
            }
            $this->sourceDirectory($package);
            $this->version($package);
            $result[$name] = $package;
            array_push($pending, ...$this->dependencyNames($package['require'] ?? [], $name));
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return list<VirionRequirement> */
    public function requirements(string $projectRoot): array
    {
        $requirements = [];
        foreach ($this->packages($projectRoot) as $name => $package) {
            $requirements[] = new VirionRequirement(self::localName($name), $this->version($package), $projectRoot . '/composer.lock:' . $name, $this->fingerprint($package));
        }
        return $requirements;
    }

    public static function localName(string $name): string
    {
        return str_replace('/', '.', $name);
    }

    public function sameMetadata(mixed $left, mixed $right): bool
    {
        return $this->canonical($left) === $this->canonical($right);
    }

    /** @param array<string, mixed> $package */
    public function fingerprint(array $package): string
    {
        $metadata = array_intersect_key($package, array_flip(['name', 'version', 'source', 'dist', 'autoload', 'require', 'extra']));
        return hash('sha256', json_encode($this->canonical($metadata), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonical($entry);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /** @param array<string, mixed> $package */
    public function version(array $package): string
    {
        $version = $package['version'] ?? null;
        if (!is_string($version)) {
            throw new BuildException('Missing Composer package version.');
        }
        $semanticVersion = ltrim($version, 'v');
        if (VirionVersionConstraint::isValidVersion($semanticVersion)) {
            return $semanticVersion;
        }
        $name = is_string($package['name'] ?? null) ? $package['name'] : 'unknown';
        $source = $package['source'] ?? null;
        $reference = is_array($source) ? ($source['reference'] ?? null) : null;
        if (preg_match('~^dev-[A-Za-z0-9._/-]+$~D', $version) !== 1
            || !is_array($source)
            || ($source['type'] ?? null) !== 'git'
            || !is_string($reference)
            || preg_match('/^[a-fA-F0-9]{40}$/D', $reference) !== 1) {
            throw new BuildException("Composer virion {$name} needs a semantic version or a development branch locked to a full Git commit in composer.lock.");
        }
        $dist = $package['dist'] ?? null;
        if (is_array($dist) && isset($dist['reference']) && $dist['reference'] !== $reference) {
            throw new BuildException("Composer virion {$name} has conflicting source and dist commit references in composer.lock.");
        }
        return '0.0.0-commit.' . strtolower($reference);
    }

    /** @param array<string, mixed> $package */
    public function antigen(array $package): string
    {
        $extra = $this->object($package['extra'] ?? null, 'Composer package extra');
        $virion = $this->object($extra['virion'] ?? null, 'Composer package extra.virion');
        $antigen = $virion['namespace-root'] ?? null;
        if (!is_string($antigen)) {
            throw new BuildException('Missing Composer virion namespace-root.');
        }
        return $antigen;
    }

    /** @param array<string, mixed> $package */
    public function excludesRootResources(array $package): bool
    {
        $extra = $this->object($package['extra'] ?? null, 'Composer package extra');
        $virion = $this->object($extra['virion'] ?? null, 'Composer package extra.virion');
        $value = $virion['exclude-root-resources'] ?? false;
        if (!is_bool($value)) {
            $name = is_string($package['name'] ?? null) ? $package['name'] : 'unknown';
            throw new BuildException("Composer virion {$name} extra.virion.exclude-root-resources must be true or false.");
        }

        return $value;
    }

    /** @param array<string, mixed> $package */
    public function sourceDirectory(array $package): string
    {
        $antigen = $this->antigen($package);
        $autoload = $package['autoload'] ?? null;
        if (!is_array($autoload) || count($autoload) !== 1) {
            throw new BuildException("Composer virion {$antigen} requires one PSR-4, namespace-based PSR-0, or classmap mapping. files and multiple roots are unsupported.");
        }
        $kind = array_key_first($autoload);
        $mapping = $autoload[$kind];
        if ($kind === 'classmap' && is_array($mapping) && array_keys($mapping) === [0] && is_string($mapping[0])) {
            $root = Path::normalizeRelativeDirectory($mapping[0]);
            $namespace = str_replace('\\', '/', $antigen);
            return $root === '' ? $namespace : $root . '/' . $namespace;
        }
        if (!in_array($kind, ['psr-4', 'psr-0'], true)) {
            throw new BuildException("Composer virion {$antigen} requires one PSR-4, namespace-based PSR-0, or classmap mapping. files and multiple roots are unsupported.");
        }
        if (!is_array($mapping)
            || array_keys($mapping) !== [$antigen . '\\']) {
            throw new BuildException("Composer virion {$antigen} requires one namespace mapping from {$antigen}\\ to one directory.");
        }
        $root = $mapping[$antigen . '\\'];
        if (is_array($root) && array_keys($root) === [0]) {
            $root = $root[0];
        }
        if (!is_string($root)) {
            throw new BuildException("Composer virion {$antigen} requires one source directory. Multiple autoload roots are unsupported.");
        }
        $root = Path::normalizeRelativeDirectory($root);
        if ($kind !== 'psr-0') {
            return $root;
        }
        $namespace = str_replace('\\', '/', $antigen);

        return $root === '' ? $namespace : $root . '/' . $namespace;
    }

    /** @return array<string, mixed> */
    public function object(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new BuildException("Expected a JSON object in {$path}.");
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new BuildException("Expected named JSON object keys in {$path}.");
            }
            $result[$key] = $entry;
        }
        return $result;
    }

    /** @return list<string> */
    public function dependencyNames(mixed $requirements, string $source): array
    {
        if (!is_array($requirements)) {
            throw new BuildException("Composer require in {$source} must be an object.");
        }
        $names = [];
        foreach ($requirements as $name => $constraint) {
            if (!is_string($name) || !is_string($constraint)) {
                throw new BuildException("Invalid Composer require entry in {$source}.");
            }
            if (preg_match('/^(php(?:-64bit|-ipv6|-zts|-debug)?|(?:ext|lib|composer)-[^\/]+)$/D', $name) === 1
                || $name === 'axolotl-pm/pocketmine-mp') {
                continue;
            }
            if (preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $name) !== 1) {
                throw new BuildException("Invalid Composer package name {$name} in {$source}.");
            }
            $names[] = $name;
        }
        return $names;
    }

    /** @return array<string, mixed> */
    public function readJson(string $path): array
    {
        if (!is_file($path) || Filesystem::isLink($path)) {
            throw new BuildException("Missing or unsafe {$path}. Run composer install --no-dev --no-scripts --no-plugins explicitly before prepare.");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new BuildException("Cannot read {$path}.");
        }
        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new BuildException("Cannot parse {$path}: {$error->getMessage()}", 0, $error);
        }
        return $this->object($value, $path);
    }
}
