<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Command;

use function basename;
use function count;
use function dirname;
use function in_array;
use function is_file;
use function is_string;

use NhanAZ\DevTools\Build\ArtifactInspector;
use NhanAZ\DevTools\Build\ArtifactSetInstaller;
use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\BuildException;
use NhanAZ\DevTools\Build\DualArtifactBuilder;
use NhanAZ\DevTools\Build\PharArchiveReader;
use NhanAZ\DevTools\Build\PharBuilder;
use NhanAZ\DevTools\Build\PharExtractor;
use NhanAZ\DevTools\Build\PluginProjectLocator;
use NhanAZ\DevTools\Build\ProjectRole;
use NhanAZ\DevTools\Build\ProjectRoleDetector;
use NhanAZ\DevTools\Build\VirionBuilder;
use NhanAZ\DevTools\Build\VirionProjectLocator;
use NhanAZ\DevTools\Build\VirionResolver;
use NhanAZ\DevTools\Build\ZipArchiveWriter;
use NhanAZ\DevTools\Loader\FolderPluginDiscovery;
use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Support\PhpSourceInspector;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Validation\PluginProjectValidator;
use NhanAZ\DevTools\Virion\VirionClassScanner;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionProjectSelector;

use function preg_match;
use function realpath;
use function str_ends_with;
use function strtolower;
use function substr;

/**
 * Runs one server-side build, extract or doctor operation without PocketMine objects.
 *
 * The worker thread creates all services from paths supplied by the main thread. This
 * keeps builders independent from the server and prevents non-thread-safe services
 * from crossing the thread boundary.
 */
final class BuildWorkerApplication
{
    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, messages: list<string>}
     */
    public static function run(string $operation, array $payload): array
    {
        try {
            $messages = match ($operation) {
                'doctor' => self::doctor($payload),
                'build' => self::build($payload),
                'extract' => self::extract($payload),
                default => throw new BuildException("Unknown background operation {$operation}."),
            };

            return ['success' => true, 'messages' => $messages];
        } catch (\Throwable $error) {
            return [
                'success' => false,
                'messages' => ["DevTools could not complete the command.\n{$error->getMessage()}"],
            ];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function doctor(array $payload): array
    {
        $services = self::services($payload);
        $selection = $payload['selection'] ?? null;
        if ($selection !== null && !is_string($selection)) {
            throw new BuildException('The doctor selection must be a string.');
        }
        $projects = $selection === null
            ? $services['pluginDiscovery']->discover($services['pluginsDirectory'])
            : [$services['projectLocator']->locate($selection, $services['pluginsDirectory'])];
        if ($projects === []) {
            return ["No development plugin folders were found in {$services['pluginsDirectory']}."];
        }

        $messages = [];
        foreach ($projects as $project) {
            $result = $services['validator']->validate($project);
            $messages[] = "Checking {$project}";
            foreach ($result->issues() as $issue) {
                $messages[] = $issue->format();
            }
            if ($result->hasErrors()) {
                continue;
            }
            try {
                $manifest = $services['manifestReader']->read($project . DIRECTORY_SEPARATOR . 'plugin.yml');
                $config = $services['configResolver']->resolve($project, $manifest->description->getName());
                $resolved = $services['resolver']->resolve($config->virions, $services['virionsDirectory']);
                $messages[] = 'Project structure and dependency selection passed (' . count($resolved) . ' virions). Shading, static analysis and runtime tests were not run. Check /devtools virions for the shared server registry.';
            } catch (\Throwable $error) {
                $messages[] = "Dependency check failed for {$project}: {$error->getMessage()}";
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function build(array $payload): array
    {
        $services = self::services($payload);
        $target = $payload['target'] ?? 'plugin';
        $selection = $payload['selection'] ?? null;
        $overwrite = $payload['overwrite'] ?? false;
        if (!is_string($target) || !is_string($selection) || !is_bool($overwrite)) {
            throw new BuildException('Invalid background build payload.');
        }

        if ($target === 'virion') {
            $project = $services['virionProjectLocator']->locate($selection, $services['virionsDirectory']);
            $result = $services['virionBuilder']->build($project, $services['buildDirectory'], $overwrite);

            return [
                "Built virion {$result->manifest->name} successfully.",
                "Files: {$result->fileCount}",
                "Output: {$result->outputPath}",
            ];
        }

        $project = $services['projectLocator']->locate($selection, $services['pluginsDirectory']);
        if ($target === 'both') {
            if ($services['roleDetector']->detect($project) !== ProjectRole::DUAL) {
                throw new BuildException("Build type both requires {$project} to contain both plugin.yml and virion.yml.");
            }
            $pluginName = $services['manifestReader']->read($project . DIRECTORY_SEPARATOR . 'plugin.yml')->description->getName();
            $virionName = $services['virionManifestReader']->read($project . DIRECTORY_SEPARATOR . 'virion.yml')->name;
            $artifacts = $services['dualArtifactBuilder']->build(
                $project,
                $services['virionsDirectory'],
                $services['buildDirectory'],
                $overwrite,
                $pluginName . '-plugin',
                $virionName . '-virion',
            );

            return [
                'Built dual-role project successfully.',
                "Plugin output: {$artifacts['plugin']}",
                "Virion output: {$artifacts['virion']}",
            ];
        }

        $result = $services['builder']->build($project, $services['virionsDirectory'], $services['buildDirectory'], $overwrite);

        return [
            "Built {$result->pluginName} successfully.",
            "Files: {$result->fileCount}",
            'Shaded virions: ' . count($result->shadedVirions),
            "Output: {$result->outputPath}",
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function extract(array $payload): array
    {
        $services = self::services($payload);
        $selection = $payload['selection'] ?? null;
        $format = $payload['format'] ?? 'folder';
        $type = $payload['type'] ?? 'auto';
        $overwrite = $payload['overwrite'] ?? false;
        if (!is_string($selection) || !is_string($format) || !is_string($type) || !is_bool($overwrite)) {
            throw new BuildException('Invalid background extract payload.');
        }

        $path = self::locatePhar($selection, $services['pluginsDirectory'], $services['virionsDirectory'], $services['buildDirectory']);
        $detectedType = $services['artifactInspector']->inspect($path)['type'];
        if (!is_string($detectedType)) {
            throw new BuildException('Artifact type could not be detected.');
        }
        if ($type === 'auto') {
            $type = $detectedType;
        }
        if ($type === 'both') {
            throw new BuildException('A dual-role artifact requires --type=plugin or --type=virion for extraction.');
        }
        if (!in_array($type, ['plugin', 'virion'], true) || !in_array($format, ['folder', 'zip'], true)) {
            throw new BuildException('Usage: /devtools extract <phar> [--format=folder|zip] [--type=auto|plugin|virion] [--overwrite]');
        }
        if (!ArtifactInspector::roleMatches($detectedType, $type)) {
            throw new BuildException("Artifact type {$detectedType} does not match requested type {$type}.");
        }

        $name = basename($path);
        if (str_ends_with(strtolower($name), '.phar')) {
            $name = substr($name, 0, -5);
        }
        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw new BuildException("Cannot derive a safe plugin folder name from {$path}.");
        }
        Path::normalizeRelative($name);

        if ($format === 'zip') {
            $destination = Path::join(realpath($services['buildDirectory']) ?: $services['buildDirectory'], $name . '.zip');
            $count = $services['zipWriter']->write(new PharArchiveReader($path), $destination, $overwrite);

            return ["Archived {$count} files to {$destination}."];
        }

        $baseDirectory = $type === 'virion' ? $services['virionsDirectory'] : $services['pluginsDirectory'];
        $base = realpath($baseDirectory);
        if ($base === false) {
            throw new BuildException("Cannot resolve {$type} directory {$baseDirectory}.");
        }
        $destination = Path::join($base, $name);
        if (dirname($destination) !== $base) {
            throw new BuildException("Refusing extraction destination outside {$base}.");
        }
        $count = $services['extractor']->extract(new PharArchiveReader($path), $destination, $overwrite);

        return ["Extracted {$count} files to {$destination}."];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     pluginDiscovery: FolderPluginDiscovery,
     *     validator: PluginProjectValidator,
     *     manifestReader: PluginManifestReader,
     *     configResolver: BuildConfigResolver,
     *     resolver: VirionResolver,
     *     projectLocator: PluginProjectLocator,
     *     builder: PharBuilder,
     *     extractor: PharExtractor,
     *     virionBuilder: VirionBuilder,
     *     zipWriter: ZipArchiveWriter,
     *     artifactInspector: ArtifactInspector,
     *     roleDetector: ProjectRoleDetector,
     *     virionProjectLocator: VirionProjectLocator,
     *     virionManifestReader: VirionManifestReader,
     *     dualArtifactBuilder: DualArtifactBuilder,
     *     pluginsDirectory: string,
     *     virionsDirectory: string,
     *     buildDirectory: string
     * }
     */
    private static function services(array $payload): array
    {
        foreach (['pluginsDirectory', 'virionsDirectory', 'buildDirectory'] as $key) {
            if (!is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw new BuildException("Missing worker path {$key}.");
            }
        }

        $filesystem = new Filesystem();
        $yaml = new YamlReader();
        $sourceInspector = new PhpSourceInspector();
        $manifestReader = new PluginManifestReader($yaml);
        $validator = new PluginProjectValidator($manifestReader, $sourceInspector);
        $pluginDiscovery = new FolderPluginDiscovery();
        $virionManifestReader = new VirionManifestReader($yaml);
        $virionFactory = new VirionProjectFactory($virionManifestReader);
        $virionDiscovery = new VirionDiscovery($filesystem);
        $virionScanner = new VirionClassScanner($sourceInspector);
        $selector = new VirionProjectSelector();
        $configResolver = new BuildConfigResolver($yaml);
        $resolver = new VirionResolver($virionDiscovery, $virionFactory, $selector);
        $builder = new PharBuilder(
            $filesystem,
            $validator,
            $manifestReader,
            $configResolver,
            $resolver,
            $virionScanner,
            new \NhanAZ\DevTools\Build\NamespaceShader(),
            $sourceInspector,
        );
        $virionBuilder = new VirionBuilder($filesystem, $virionManifestReader, $virionFactory, $virionScanner);

        return [
            'pluginDiscovery' => $pluginDiscovery,
            'validator' => $validator,
            'manifestReader' => $manifestReader,
            'configResolver' => $configResolver,
            'resolver' => $resolver,
            'projectLocator' => new PluginProjectLocator($pluginDiscovery, $manifestReader),
            'builder' => $builder,
            'extractor' => new PharExtractor($filesystem),
            'virionBuilder' => $virionBuilder,
            'zipWriter' => new ZipArchiveWriter($filesystem),
            'artifactInspector' => new ArtifactInspector($manifestReader, $virionManifestReader),
            'roleDetector' => new ProjectRoleDetector(),
            'virionProjectLocator' => new VirionProjectLocator($virionDiscovery, $virionFactory),
            'virionManifestReader' => $virionManifestReader,
            'dualArtifactBuilder' => new DualArtifactBuilder(
                $filesystem,
                $builder,
                $virionBuilder,
                new ArtifactSetInstaller(),
            ),
            'pluginsDirectory' => $payload['pluginsDirectory'],
            'virionsDirectory' => $payload['virionsDirectory'],
            'buildDirectory' => $payload['buildDirectory'],
        ];
    }

    private static function locatePhar(string $selection, string $pluginsDirectory, string $virionsDirectory, string $buildDirectory): string
    {
        $candidates = [
            $selection,
            $buildDirectory . DIRECTORY_SEPARATOR . $selection,
            $pluginsDirectory . DIRECTORY_SEPARATOR . $selection,
            $virionsDirectory . DIRECTORY_SEPARATOR . $selection,
        ];
        if (!str_ends_with(strtolower($selection), '.phar')) {
            $candidates[] = $buildDirectory . DIRECTORY_SEPARATOR . $selection . '.phar';
            $candidates[] = $pluginsDirectory . DIRECTORY_SEPARATOR . $selection . '.phar';
            $candidates[] = $virionsDirectory . DIRECTORY_SEPARATOR . $selection . '.phar';
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $real = realpath($candidate);
                if ($real !== false) {
                    return $real;
                }
            }
        }

        throw new BuildException("Cannot find PHAR {$selection}. Check the path or the build/ directory.");
    }
}
