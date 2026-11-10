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
use AxolotlBucketLabs\DevTools\Build\ProjectDoctor;
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
use function is_bool;
use function is_file;
use function is_string;
use function preg_match;
use function realpath;
use function str_ends_with;
use function strtolower;
use function substr;
use const DIRECTORY_SEPARATOR;

/**
 * Runs one server-side build, extract or doctor operation without PocketMine objects.
 *
 * The worker thread creates all services from paths supplied by the main thread. This
 * keeps builders independent from the server and prevents non-thread-safe services
 * from crossing the thread boundary.
 */
final class BuildWorkerApplication
{
	private readonly FolderPluginDiscovery $pluginDiscovery;
	private readonly ProjectDoctor $projectDoctor;
	private readonly PluginProjectLocator $projectLocator;
	private readonly PharBuilder $builder;
	private readonly PharExtractor $extractor;
	private readonly VirionBuilder $virionBuilder;
	private readonly ZipArchiveWriter $zipWriter;
	private readonly ArtifactInspector $artifactInspector;
	private readonly VirionProjectLocator $virionProjectLocator;
	private readonly DualArtifactBuilder $dualArtifactBuilder;

	private function __construct(
		private readonly string $pluginsDirectory,
		private readonly string $virionsDirectory,
		private readonly string $buildDirectory,
		DebugLogger $debug,
		private readonly Translator $translator,
	) {
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

		$this->pluginDiscovery = $pluginDiscovery;
		$this->projectDoctor = new ProjectDoctor($validator, $manifestReader, $configResolver, $resolver);
		$this->projectLocator = new PluginProjectLocator($pluginDiscovery, $manifestReader);
		$this->builder = $builder;
		$this->extractor = new PharExtractor($filesystem, $debug);
		$this->virionBuilder = $virionBuilder;
		$this->zipWriter = new ZipArchiveWriter($filesystem);
		$this->artifactInspector = new ArtifactInspector($manifestReader, $virionManifestReader);
		$this->virionProjectLocator = new VirionProjectLocator($virionDiscovery, $virionFactory);
		$this->dualArtifactBuilder = new DualArtifactBuilder(
			$filesystem,
			$builder,
			$virionBuilder,
			new ArtifactSetInstaller(),
			$manifestReader,
			$virionManifestReader,
			new ProjectRoleDetector(),
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{success: bool, messages: list<string>, debug: list<string>}
	 */
	public static function run(string $operation, array $payload) : array
	{
		$debugMessages = [];
		$translator = null;
		$debug = new DebugLogger(($payload['debug'] ?? false) === true
			? static function (string $message) use (&$debugMessages) : void {
				$debugMessages[] = $message;
			}
			: null);
		$debug->debug("Worker operation {$operation} started.");
		try {
			$translator = Translator::fromResources(is_string($payload['locale'] ?? null) ? $payload['locale'] : 'eng');
			if (!in_array($operation, ['doctor', 'build', 'extract'], true)) {
				throw new BuildException($translator->translate('worker.unknown_operation', ['operation' => $operation]));
			}
			foreach (['pluginsDirectory', 'virionsDirectory', 'buildDirectory'] as $key) {
				if (!is_string($payload[$key] ?? null) || $payload[$key] === '') {
					throw new LocalizedException('worker.path_missing', ['key' => $key]);
				}
			}
			$application = new self($payload['pluginsDirectory'], $payload['virionsDirectory'], $payload['buildDirectory'], $debug, $translator);
			$messages = match ($operation) {
				'doctor' => $application->doctor($payload),
				'build' => $application->build($payload),
				'extract' => $application->extract($payload),
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
	private function doctor(array $payload) : array
	{
		$selection = $payload['selection'] ?? null;
		if ($selection !== null && !is_string($selection)) {
			throw new BuildException($this->translator->translate('doctor.invalid_selection'));
		}
		$projects = $selection === null
			? $this->pluginDiscovery->discover($this->pluginsDirectory)
			: [$this->projectLocator->locate($selection, $this->pluginsDirectory)];
		if ($projects === []) {
			return [$this->translator->translate('doctor.none', ['directory' => $this->pluginsDirectory])];
		}

		$messages = [];
		foreach ($projects as $project) {
			$check = $this->projectDoctor->check($project, $this->virionsDirectory);
			$result = $check->validation;
			$messages[] = $this->translator->translate('doctor.checking', ['project' => $project]);
			foreach ($result->issues() as $issue) {
				$messages[] = $issue->format();
			}
			if ($result->hasErrors()) {
				continue;
			}
			if ($check->error !== null) {
				$messages[] = $this->translator->translate('doctor.failed', ['project' => $project, 'error' => $check->error->getMessage()]);
			} else {
				$messages[] = $this->translator->translate('doctor.passed', ['count' => count($check->virions)]);
			}
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<string>
	 */
	private function build(array $payload) : array
	{
		$target = $payload['target'] ?? 'plugin';
		$selection = $payload['selection'] ?? null;
		$overwrite = $payload['overwrite'] ?? false;
		if (!is_string($target) || !is_string($selection) || !is_bool($overwrite)) {
			throw new BuildException($this->translator->translate('worker.invalid_build_payload'));
		}

		if ($target === 'virion') {
			$project = $this->virionProjectLocator->locate($selection, $this->virionsDirectory);
			$result = $this->virionBuilder->build($project, $this->buildDirectory, $overwrite);

			return [
				$this->translator->translate('build.virion', ['name' => $result->manifest->name]),
				$this->translator->translate('summary.files', ['count' => $result->fileCount]),
				$this->translator->translate('summary.output', ['path' => $result->outputPath]),
			];
		}

		$project = $this->projectLocator->locate($selection, $this->pluginsDirectory);
		if ($target === 'both') {
			$dualProject = $this->dualArtifactBuilder->prepare($project);
			$artifacts = $this->dualArtifactBuilder->build(
				$dualProject,
				$this->virionsDirectory,
				$this->buildDirectory,
				$overwrite,
			);

			return [
				$this->translator->translate('build.dual'),
				$this->translator->translate('summary.plugin_output', ['path' => $artifacts['plugin']]),
				$this->translator->translate('summary.virion_output', ['path' => $artifacts['virion']]),
			];
		}

		$result = $this->builder->build($project, $this->virionsDirectory, $this->buildDirectory, $overwrite);

		return [
			$this->translator->translate('build.plugin', ['name' => $result->pluginName]),
			$this->translator->translate('summary.files', ['count' => $result->fileCount]),
			$this->translator->translate('summary.shaded', ['count' => count($result->shadedVirions)]),
			$this->translator->translate('summary.output', ['path' => $result->outputPath]),
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<string>
	 */
	private function extract(array $payload) : array
	{
		$selection = $payload['selection'] ?? null;
		$format = $payload['format'] ?? 'folder';
		$type = $payload['type'] ?? 'auto';
		$overwrite = $payload['overwrite'] ?? false;
		if (!is_string($selection) || !is_string($format) || !is_string($type) || !is_bool($overwrite)) {
			throw new BuildException($this->translator->translate('worker.invalid_extract_payload'));
		}

		$path = self::locatePhar($selection, $this->pluginsDirectory, $this->virionsDirectory, $this->buildDirectory);
		$detectedType = $this->artifactInspector->inspect($path)['type'];
		if (!is_string($detectedType)) {
			throw new BuildException($this->translator->translate('extract.artifact_type'));
		}
		if ($type === 'auto') {
			$type = $detectedType;
		}
		if ($type === 'both') {
			throw new BuildException($this->translator->translate('extract.dual'));
		}
		if (!in_array($type, ['plugin', 'virion'], true) || !in_array($format, ['folder', 'zip'], true)) {
			throw new BuildException($this->translator->translate('extract.usage'));
		}
		if (!ArtifactInspector::roleMatches($detectedType, $type)) {
			throw new BuildException($this->translator->translate('extract.mismatch', ['detected' => $detectedType, 'requested' => $type]));
		}

		$name = basename($path);
		if (str_ends_with(strtolower($name), '.phar')) {
			$name = substr($name, 0, -5);
		}
		if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name) !== 1) {
			throw new BuildException($this->translator->translate('extract.safe_name', ['path' => $path]));
		}
		Path::normalizeRelative($name);

		if ($format === 'zip') {
			$destination = Path::join(realpath($this->buildDirectory) ?: $this->buildDirectory, $name . '.zip');
			$count = $this->zipWriter->write(new PharArchiveReader($path), $destination, $overwrite);

			return [$this->translator->translate('extract.archived', ['count' => $count, 'path' => $destination])];
		}

		$baseDirectory = $type === 'virion' ? $this->virionsDirectory : $this->pluginsDirectory;
		$base = realpath($baseDirectory);
		if ($base === false) {
			throw new BuildException($this->translator->translate('extract.directory', ['type' => $type, 'directory' => $baseDirectory]));
		}
		$destination = Path::join($base, $name);
		if (dirname($destination) !== $base) {
			throw new BuildException($this->translator->translate('extract.outside', ['path' => $base]));
		}
		$count = $this->extractor->extract(new PharArchiveReader($path), $destination, $overwrite);

		return [$this->translator->translate('extract.extracted', ['count' => $count, 'path' => $destination])];
	}

	private static function locatePhar(string $selection, string $pluginsDirectory, string $virionsDirectory, string $buildDirectory) : string
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
