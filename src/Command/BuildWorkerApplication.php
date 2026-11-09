<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

use AxolotlBucketLabs\DevTools\Build\ArtifactInspector;
use AxolotlBucketLabs\DevTools\Build\ArtifactSetInstaller;
use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\DualArtifactBuilder;
use AxolotlBucketLabs\DevTools\Build\PharArchiveReader;
use AxolotlBucketLabs\DevTools\Build\PharBuilder;
use AxolotlBucketLabs\DevTools\Build\PharExtractor;
use AxolotlBucketLabs\DevTools\Build\PluginProjectLocator;
use AxolotlBucketLabs\DevTools\Build\ProjectRole;
use AxolotlBucketLabs\DevTools\Build\ProjectRoleDetector;
use AxolotlBucketLabs\DevTools\Build\VirionBuilder;
use AxolotlBucketLabs\DevTools\Build\VirionProjectLocator;
use AxolotlBucketLabs\DevTools\Build\VirionResolver;
use AxolotlBucketLabs\DevTools\Build\ZipArchiveWriter;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\LocalizedException;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Validation\PluginProjectValidator;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;

use function basename;
use function count;
use function dirname;
use function in_array;
use function is_file;
use function is_string;
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
     * @return array{success: bool, messages: list<string>, debug: list<string>}
     */
    public static function run(string $operation, array $payload): array
    {
        $debugMessages = [];
        $translator = null;
        $debug = new DebugLogger(($payload['debug'] ?? false) === true
            ? static function (string $message) use (&$debugMessages): void {
                $debugMessages[] = $message;
            }
            : null);
        $debug->debug("Worker operation {$operation} started.");
        try {
            $translator = Translator::fromResources(is_string($payload['locale'] ?? null) ? $payload['locale'] : 'eng');
            $messages = match ($operation) {
                'doctor' => self::doctor($payload, $debug, $translator),
                'build' => self::build($payload, $debug, $translator),
                'extract' => self::extract($payload, $debug, $translator),
                default => throw new BuildException($translator->translate('worker.unknown_operation', ['operation' => $operation])),
            };
            $debug->debug("Worker operation {$operation} completed successfully.");

            return ['success' => true, 'messages' => $messages, 'debug' => $debugMessages];
        } catch (\Throwable $error) {
            $debug->debug("Worker operation {$operation} failed: {$error->getMessage()}");

            return [
                'success' => false,
                'messages' => [($translator ?? Translator::fromResources())->translate('command.failed', [
                    'error' => $error instanceof LocalizedException
                        ? $error->render($translator ?? Translator::fromResources())
                        : $error->getMessage(),
                ])],
                'debug' => $debugMessages,
            ];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function doctor(array $payload, DebugLogger $debug, Translator $translator): array
    {
        $services = self::services($payload, $debug);
        $selection = $payload['selection'] ?? null;
        if ($selection !== null && !is_string($selection)) {
            throw new BuildException($translator->translate('doctor.invalid_selection'));
        }
        $projects = $selection === null
            ? $services['pluginDiscovery']->discover($services['pluginsDirectory'])
            : [$services['projectLocator']->locate($selection, $services['pluginsDirectory'])];
        if ($projects === []) {
            return [$translator->translate('doctor.none', ['directory' => $services['pluginsDirectory']])];
        }

        $messages = [];
        foreach ($projects as $project) {
            $result = $services['validator']->validate($project);
            $messages[] = $translator->translate('doctor.checking', ['project' => $project]);
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
                $messages[] = $translator->translate('doctor.passed', ['count' => count($resolved)]);
            } catch (\Throwable $error) {
                $messages[] = $translator->translate('doctor.failed', ['project' => $project, 'error' => $error->getMessage()]);
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function build(array $payload, DebugLogger $debug, Translator $translator): array
    {
        $services = self::services($payload, $debug);
        $target = $payload['target'] ?? 'plugin';
        $selection = $payload['selection'] ?? null;
        $overwrite = $payload['overwrite'] ?? false;
        if (!is_string($target) || !is_string($selection) || !is_bool($overwrite)) {
            throw new BuildException($translator->translate('worker.invalid_build_payload'));
        }

        if ($target === 'virion') {
            $project = $services['virionProjectLocator']->locate($selection, $services['virionsDirectory']);
            $result = $services['virionBuilder']->build($project, $services['buildDirectory'], $overwrite);

            return [
                $translator->translate('build.virion', ['name' => $result->manifest->name]),
                $translator->translate('summary.files', ['count' => $result->fileCount]),
                $translator->translate('summary.output', ['path' => $result->outputPath]),
            ];
        }

        $project = $services['projectLocator']->locate($selection, $services['pluginsDirectory']);
        if ($target === 'both') {
            if ($services['roleDetector']->detect($project) !== ProjectRole::DUAL) {
                throw new BuildException($translator->translate('build.both_required', ['project' => $project]));
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
                $translator->translate('build.dual'),
                $translator->translate('summary.plugin_output', ['path' => $artifacts['plugin']]),
                $translator->translate('summary.virion_output', ['path' => $artifacts['virion']]),
            ];
        }

        $result = $services['builder']->build($project, $services['virionsDirectory'], $services['buildDirectory'], $overwrite);

        return [
            $translator->translate('build.plugin', ['name' => $result->pluginName]),
            $translator->translate('summary.files', ['count' => $result->fileCount]),
            $translator->translate('summary.shaded', ['count' => count($result->shadedVirions)]),
            $translator->translate('summary.output', ['path' => $result->outputPath]),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function extract(array $payload, DebugLogger $debug, Translator $translator): array
    {
        $services = self::services($payload, $debug);
        $selection = $payload['selection'] ?? null;
        $format = $payload['format'] ?? 'folder';
        $type = $payload['type'] ?? 'auto';
        $overwrite = $payload['overwrite'] ?? false;
        if (!is_string($selection) || !is_string($format) || !is_string($type) || !is_bool($overwrite)) {
            throw new BuildException($translator->translate('worker.invalid_extract_payload'));
        }

        $path = self::locatePhar($selection, $services['pluginsDirectory'], $services['virionsDirectory'], $services['buildDirectory']);
        $detectedType = $services['artifactInspector']->inspect($path)['type'];
        if (!is_string($detectedType)) {
            throw new BuildException($translator->translate('extract.artifact_type'));
        }
        if ($type === 'auto') {
            $type = $detectedType;
        }
        if ($type === 'both') {
            throw new BuildException($translator->translate('extract.dual'));
        }
        if (!in_array($type, ['plugin', 'virion'], true) || !in_array($format, ['folder', 'zip'], true)) {
            throw new BuildException($translator->translate('extract.usage'));
        }
        if (!ArtifactInspector::roleMatches($detectedType, $type)) {
            throw new BuildException($translator->translate('extract.mismatch', ['detected' => $detectedType, 'requested' => $type]));
        }

        $name = basename($path);
        if (str_ends_with(strtolower($name), '.phar')) {
            $name = substr($name, 0, -5);
        }
        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw new BuildException($translator->translate('extract.safe_name', ['path' => $path]));
        }
        Path::normalizeRelative($name);

        if ($format === 'zip') {
            $destination = Path::join(realpath($services['buildDirectory']) ?: $services['buildDirectory'], $name . '.zip');
            $count = $services['zipWriter']->write(new PharArchiveReader($path), $destination, $overwrite);

            return [$translator->translate('extract.archived', ['count' => $count, 'path' => $destination])];
        }

        $baseDirectory = $type === 'virion' ? $services['virionsDirectory'] : $services['pluginsDirectory'];
        $base = realpath($baseDirectory);
        if ($base === false) {
            throw new BuildException($translator->translate('extract.directory', ['type' => $type, 'directory' => $baseDirectory]));
        }
        $destination = Path::join($base, $name);
        if (dirname($destination) !== $base) {
            throw new BuildException($translator->translate('extract.outside', ['path' => $base]));
        }
        $count = $services['extractor']->extract(new PharArchiveReader($path), $destination, $overwrite);

        return [$translator->translate('extract.extracted', ['count' => $count, 'path' => $destination])];
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
    private static function services(array $payload, DebugLogger $debug): array
    {
        foreach (['pluginsDirectory', 'virionsDirectory', 'buildDirectory'] as $key) {
            if (!is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw new LocalizedException('worker.path_missing', ['key' => $key]);
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
        $resolver = new VirionResolver($virionDiscovery, $virionFactory, $selector, $debug);
        $builder = new PharBuilder(
            $filesystem,
            $validator,
            $manifestReader,
            $configResolver,
            $resolver,
            $virionScanner,
            new \AxolotlBucketLabs\DevTools\Build\NamespaceShader(),
            $sourceInspector,
            $debug,
        );
        $virionBuilder = new VirionBuilder($filesystem, $virionManifestReader, $virionFactory, $virionScanner, $debug);

        return [
            'pluginDiscovery' => $pluginDiscovery,
            'validator' => $validator,
            'manifestReader' => $manifestReader,
            'configResolver' => $configResolver,
            'resolver' => $resolver,
            'projectLocator' => new PluginProjectLocator($pluginDiscovery, $manifestReader),
            'builder' => $builder,
            'extractor' => new PharExtractor($filesystem, $debug),
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

        throw new LocalizedException('extract.phar_not_found', ['selection' => $selection]);
    }
}
