<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Cli;

use InvalidArgumentException;
use NhanAZ\DevTools\Build\ArtifactInspector;
use NhanAZ\DevTools\Build\ArtifactSetInstaller;
use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\BuildException;
use NhanAZ\DevTools\Build\ComposerVirionPreparer;
use NhanAZ\DevTools\Build\DualArtifactBuilder;
use NhanAZ\DevTools\Build\PharArchiveReader;
use NhanAZ\DevTools\Build\PharBuilder;
use NhanAZ\DevTools\Build\PharExtractor;
use NhanAZ\DevTools\Build\ProjectRole;
use NhanAZ\DevTools\Build\ProjectRoleDetector;
use NhanAZ\DevTools\Build\VirionBuilder;
use NhanAZ\DevTools\Build\VirionResolver;
use NhanAZ\DevTools\Build\ZipArchiveWriter;
use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Validation\PluginProjectValidator;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use Throwable;

final class Application
{
    public function __construct(
        private readonly string $version,
        private readonly PluginManifestReader $manifestReader,
        private readonly PluginProjectValidator $validator,
        private readonly BuildConfigResolver $configResolver,
        private readonly VirionResolver $resolver,
        private readonly PharBuilder $builder,
        private readonly VirionBuilder $virionBuilder,
        private readonly PharExtractor $extractor,
        private readonly ZipArchiveWriter $zipWriter,
        private readonly ArtifactInspector $inspector,
        private readonly ComposerVirionPreparer $preparer,
        private readonly VirionManifestReader $virionManifestReader,
        private readonly ProjectRoleDetector $roleDetector,
        private readonly ?DualArtifactBuilder $dualArtifactBuilder = null,
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $json = in_array('--json', $arguments, true);
        $command = 'help';
        $checks = ['structure' => 'not_run', 'dependencies' => 'not_run', 'artifact' => 'not_run', 'static_analysis' => 'not_run', 'runtime' => 'not_run'];
        $diagnostics = [];
        $data = [];
        $exit = 0;
        try {
            [$command, $options] = $this->parse($arguments, $command);
            if ($command === 'help') {
                $data = ['help' => self::help()];
            } elseif ($command === 'version') {
                $data = ['version' => $this->version];
            } else {
                $cwd = getcwd();
                if ($cwd === false) {
                    throw new \RuntimeException('Cannot determine the working directory.');
                }
                $project = $this->absolute($options['project'] ?? '.', $cwd);
                if (in_array($command, ['prepare', 'doctor', 'build'], true) && !is_dir($project)) {
                    throw new \RuntimeException("Project directory does not exist: {$project}");
                }
                $virions = $this->absolute($options['virions'] ?? 'virions', $project);
                $overwrite = isset($options['overwrite']);
                $type = $options['type'] ?? 'plugin';
                if ($command === 'prepare') {
                    $checks['dependencies'] = 'failed';
                    $data = ['directory' => $virions, 'dependencies' => $this->preparer->prepare($project, $virions)];
                    $checks['dependencies'] = 'passed';
                } elseif ($command === 'inspect') {
                    $checks['artifact'] = 'failed';
                    $data = $this->inspector->inspect($this->absolute($this->required($options, 'artifact'), $project));
                    $checks['artifact'] = 'passed';
                } elseif ($command === 'extract') {
                    $artifact = $this->absolute($this->required($options, 'artifact'), $project);
                    $destination = $this->absolute($this->required($options, 'out'), $project);
                    $format = $options['format'] ?? 'folder';
                    $artifactType = $options['type'] ?? 'auto';
                    $checks['artifact'] = 'failed';
                    $inspection = $this->inspector->inspect($artifact);
                    $checks['artifact'] = 'passed';
                    $detected = $inspection['type'] ?? null;
                    if (!is_string($detected)) {
                        throw new InvalidArgumentException('Artifact type could not be detected.');
                    }
                    if ($artifactType === 'auto') {
                        if ($detected === ProjectRole::DUAL->value) {
                            throw new InvalidArgumentException('A dual-role artifact requires --type=plugin or --type=virion for extraction.');
                        }
                        $artifactType = $detected;
                    } else {
                        $this->assertArtifactType($inspection, $artifactType);
                    }
                    if ($format === 'folder') {
                        $files = $this->extractor->extract(new PharArchiveReader($artifact), $destination, $overwrite);
                        $data = ['destination' => realpath($destination), 'format' => $format, 'files' => $files];
                    } else {
                        $files = $this->zipWriter->write(new PharArchiveReader($artifact), $destination, $overwrite);
                        $data = ['destination' => realpath($destination), 'format' => $format, 'files' => $files];
                    }
                } else {
                    if ($command === 'build' && $type === 'virion') {
                        $checks['structure'] = 'failed';
                        $this->virionManifestReader->read($project . '/virion.yml');
                        $checks['structure'] = 'passed';
                        $checks['artifact'] = 'failed';
                        $result = $this->virionBuilder->build($project, $this->absolute($options['out'] ?? 'build', $project), $overwrite);
                        $data = $this->inspector->inspect($result->outputPath) + ['project' => $project];
                        $checks['artifact'] = 'passed';
                    } elseif ($command === 'build' && $type === 'both') {
                        $checks['structure'] = 'failed';
                        $role = $this->roleDetector->detect($project);
                        if ($role !== ProjectRole::DUAL) {
                            throw new BuildException("Build type both requires a project containing both plugin.yml and virion.yml: {$project}");
                        }
                        $pluginManifest = $this->manifestReader->read($project . '/plugin.yml');
                        $virionManifest = $this->virionManifestReader->read($project . '/virion.yml');
                        $checks['structure'] = 'passed';
                        $checks['dependencies'] = 'failed';
                        $config = $this->configResolver->resolve($project, $pluginManifest->description->getName());
                        $virionsResolved = $this->resolver->resolve($config->virions, $virions);
                        $checks['dependencies'] = 'passed';
                        $output = $this->absolute($options['out'] ?? 'build', $project);
                        $checks['artifact'] = 'failed';
                        $dualArtifactBuilder = $this->dualArtifactBuilder ?? new DualArtifactBuilder(
                            new Filesystem(),
                            $this->builder,
                            $this->virionBuilder,
                            new ArtifactSetInstaller(),
                        );
                        $artifacts = $dualArtifactBuilder->build(
                            $project,
                            $virions,
                            $output,
                            $overwrite,
                            $pluginManifest->description->getName() . '-plugin',
                            $virionManifest->name . '-virion',
                        );
                        $data = [
                            'project' => $project,
                            'artifacts' => [$this->inspector->inspect($artifacts['plugin']), $this->inspector->inspect($artifacts['virion'])],
                        ];
                        $checks['artifact'] = 'passed';
                    } else {
                        $validation = $this->validator->validate($project);
                        foreach ($validation->issues() as $issue) {
                            $diagnostics[] = ['code' => $issue->code, 'severity' => $issue->severity->value, 'message' => $issue->message, 'file' => $issue->path, 'line' => null, 'suggestion' => $issue->suggestion];
                        }
                        $checks['structure'] = $validation->hasErrors() ? 'failed' : 'passed';
                        if ($validation->hasErrors()) {
                            $exit = 1;
                        } else {
                            $manifest = $this->manifestReader->read($project . '/plugin.yml');
                            $checks['dependencies'] = 'failed';
                            $config = $this->configResolver->resolve($project, $manifest->description->getName());
                            $virionsResolved = $this->resolver->resolve($config->virions, $virions);
                            $checks['dependencies'] = 'passed';
                            $data = [
                                'project' => $project,
                                'plugin' => ['name' => $manifest->description->getName(), 'version' => $manifest->description->getVersion()],
                                'dependencies' => array_map(static fn($virion): array => ['name' => $virion->manifest->name, 'version' => $virion->manifest->version, 'antigen' => $virion->manifest->antigen, 'source' => $virion->location], $virionsResolved),
                            ];
                            if ($command === 'build') {
                                $checks['artifact'] = 'failed';
                                $result = $this->builder->build($project, $virions, $this->absolute($options['out'] ?? 'build', $project), $overwrite);
                                $data = $this->inspector->inspect($result->outputPath) + ['dependencies' => $result->dependencies, 'shaded_namespaces' => (object) $result->shadedVirions];
                                $checks['artifact'] = 'passed';
                            }
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $exit = $error instanceof InvalidArgumentException ? 2 : 1;
            $diagnostics[] = [
                'code' => $exit === 2 ? 'cli.usage' : 'operation.' . $command . '_failed',
                'severity' => 'error', 'message' => $error->getMessage(), 'file' => null, 'line' => null,
                'suggestion' => $exit === 2 ? 'Run --help for supported commands and options.' : 'Correct the reported input or environment problem and retry. No runtime validation is implied.',
            ];
        }
        $response = ['schema_version' => 1, 'success' => $exit === 0, 'command' => $command, 'tool' => ['name' => 'DevTools', 'version' => $this->version], 'data' => (object) $data, 'diagnostics' => $diagnostics, 'checks' => $checks];
        if ($json) {
            fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        } else {
            foreach ($diagnostics as $diagnostic) {
                fwrite(STDERR, "[{$diagnostic['code']}] {$diagnostic['message']}\n");
                if ($diagnostic['suggestion'] !== null) {
                    fwrite(STDERR, "{$diagnostic['suggestion']}\n");
                }
            }
            if ($exit === 0) {
                fwrite(STDOUT, $command === 'help' ? self::help() : ($command === 'version' ? "DevTools {$this->version}\n" : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"));
                if (!in_array($command, ['help', 'version'], true)) {
                    fwrite(STDOUT, "Static analysis and Axolotl-PM runtime tests were not run.\n");
                }
            }
        }

        return $exit;
    }

    /** @param list<string> $arguments
     * @return array{string, array<string, string>}
     */
    private function parse(array $arguments, string &$reportedCommand): array
    {
        $command = null;
        $options = [];
        foreach ($arguments as $argument) {
            if ($argument === '--json') {
                continue;
            }
            if ($argument === '--help' || $argument === '--version') {
                $options[substr($argument, 2)] = 'true';
                continue;
            }
            if ($argument === '--overwrite') {
                $options['overwrite'] = 'true';
                continue;
            }
            if (preg_match('/^--(project|virions|out|artifact|type|format)=(.+)$/Ds', $argument, $match) === 1) {
                if (isset($options[$match[1]]) || str_contains($match[2], "\0") || preg_match('/[\r\n]/', $match[2]) === 1) {
                    throw new InvalidArgumentException("Duplicate or invalid path option: {$match[1]}");
                }
                $options[$match[1]] = $match[2];
                continue;
            }
            if ($command === null && in_array($argument, ['build', 'doctor', 'inspect', 'extract', 'prepare'], true)) {
                $command = $argument;
                $reportedCommand = $command;
                continue;
            }
            throw new InvalidArgumentException("Unknown command or argument: {$argument}");
        }
        if (isset($options['help']) || $arguments === [] || $arguments === ['--json']) {
            return ['help', []];
        }
        if (isset($options['version'])) {
            return ['version', []];
        }
        if ($command === null) {
            throw new InvalidArgumentException('Specify a command.');
        }
        $allowed = match ($command) {
            'build' => ['project', 'virions', 'out', 'overwrite', 'type'],
            'doctor', 'prepare' => ['project', 'virions'],
            'inspect' => ['project', 'artifact'],
            'extract' => ['project', 'artifact', 'out', 'overwrite', 'format', 'type'],
        };
        foreach (array_keys($options) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Option --{$key} is not supported for {$command}.");
            }
        }
        if (isset($options['type'])) {
            $allowedTypes = $command === 'build' ? ['plugin', 'virion', 'both'] : ['auto', 'plugin', 'virion'];
            if (!in_array($options['type'], $allowedTypes, true)) {
                throw new InvalidArgumentException($command === 'build'
                    ? 'Option --type for build must be plugin, virion or both.'
                    : 'Option --type for extract must be auto, plugin or virion.');
            }
        }
        if (isset($options['format']) && !in_array($options['format'], ['folder', 'zip'], true)) {
            throw new InvalidArgumentException('Option --format must be folder or zip.');
        }

        return [$command, $options];
    }

    /** @param array<string, string> $options */
    private function required(array $options, string $key): string
    {
        return $options[$key] ?? throw new InvalidArgumentException("Required option: --{$key}=PATH");
    }

    /** @param array<string, mixed> $inspection */
    private function assertArtifactType(array $inspection, string $type): void
    {
        if ($type === 'auto') {
            return;
        }
        $detected = $inspection['type'] ?? null;
        if (!is_string($detected)) {
            throw new InvalidArgumentException('Artifact type could not be detected.');
        }
        if (!ArtifactInspector::roleMatches($detected, $type)) {
            throw new InvalidArgumentException("Artifact type {$detected} does not match requested type {$type}.");
        }
    }

    private function absolute(string $path, string $base): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
            throw new InvalidArgumentException('CLI paths must be local filesystem paths, not stream URLs.');
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return $base . DIRECTORY_SEPARATOR . $path;
    }

    private static function help(): string
    {
        return <<<'HELP'
DevTools for Axolotl-PM plugin development
Usage: php [-d phar.readonly=0] bin/devtools.php COMMAND [OPTIONS]
  build    --project=PATH [--type=plugin|virion|both] [--virions=PATH] [--out=PATH] [--overwrite]
  doctor   --project=PATH [--virions=PATH]
  prepare  --project=PATH [--virions=PATH]
  inspect  --artifact=PATH [--project=PATH]
  extract  --artifact=PATH --out=PATH [--type=auto|plugin|virion] [--format=folder|zip] [--project=PATH] [--overwrite]
  --help | --version
--json writes one schema_version=1 result to stdout. Logs and PHP diagnostics use stderr.
The project defaults to the current directory. Virions use PROJECT/virions. Builds use PROJECT/build.
Other relative paths resolve from project. Commands do not prompt. Replacement requires --overwrite.
prepare imports supported locked Composer virions after composer install --no-dev --no-scripts --no-plugins.
doctor checks structure and dependency selection, not full shading, static analysis or runtime.
inspect checks the PHAR signature, manifest, main entry and hash without executing plugin code.
Exit codes are 0 for success, 1 for operation or validation failure, and 2 for invalid usage.

HELP;
    }
}
