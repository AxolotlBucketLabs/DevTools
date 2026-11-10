<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Cli;

use AxolotlBucketLabs\DevTools\Build\ArtifactInspector;
use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\ComposerVirionPreparer;
use AxolotlBucketLabs\DevTools\Build\DualArtifactBuilder;
use AxolotlBucketLabs\DevTools\Build\PharArchiveReader;
use AxolotlBucketLabs\DevTools\Build\PharBuilder;
use AxolotlBucketLabs\DevTools\Build\PharExtractor;
use AxolotlBucketLabs\DevTools\Build\ProjectDoctor;
use AxolotlBucketLabs\DevTools\Build\ProjectRole;
use AxolotlBucketLabs\DevTools\Build\VirionBuilder;
use AxolotlBucketLabs\DevTools\Build\VirionResolver;
use AxolotlBucketLabs\DevTools\Build\ZipArchiveWriter;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\LocalizedException;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use InvalidArgumentException;
use Throwable;
use function array_diff;
use function array_keys;
use function array_map;
use function fwrite;
use function getcwd;
use function getenv;
use function in_array;
use function is_dir;
use function is_string;
use function json_encode;
use function preg_match;
use function realpath;
use function str_contains;
use function str_starts_with;
use function substr;
use const DIRECTORY_SEPARATOR;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const STDERR;
use const STDOUT;

final class Application
{
	public function __construct(
		private readonly string $version,
		private readonly ProjectDoctor $doctor,
		private readonly BuildConfigResolver $configResolver,
		private readonly VirionResolver $resolver,
		private readonly PharBuilder $builder,
		private readonly VirionBuilder $virionBuilder,
		private readonly PharExtractor $extractor,
		private readonly ZipArchiveWriter $zipWriter,
		private readonly ArtifactInspector $inspector,
		private readonly ComposerVirionPreparer $preparer,
		private readonly VirionManifestReader $virionManifestReader,
		private readonly DualArtifactBuilder $dualArtifactBuilder,
		private readonly ?DebugLogger $debug = null,
		private readonly ?Translator $translator = null,
	) {}

	/** @param list<string> $arguments */
	public function run(array $arguments) : int
	{
		$this->debug?->debug('CLI invocation started.');
		$json = in_array('--json', $arguments, true);
		$command = 'help';
		$checks = ['structure' => 'not_run', 'dependencies' => 'not_run', 'artifact' => 'not_run', 'static_analysis' => 'not_run', 'runtime' => 'not_run'];
		$diagnostics = [];
		$data = [];
		$exit = 0;
		$translator = $this->translator ?? Translator::fromResources();
		try {
			foreach ($arguments as $argument) {
				if (preg_match('/^--lang=(.+)$/D', $argument, $localeMatch) === 1) {
					$translator = $translator->withLocale($localeMatch[1]);
					break;
				}
			}
			[$command, $options] = $this->parse($arguments, $command);
			$translator = $translator->withLocale($options['lang'] ?? (getenv('DEVTOOLS_LANG') ?: 'eng'));
			$this->debug?->debug("CLI command {$command} selected.");
			if ($command === 'help') {
				$data = ['help' => self::help($translator)];
			} elseif ($command === 'version') {
				$data = ['version' => $this->version, 'locale' => $translator->locale()];
			} else {
				$cwd = getcwd();
				if ($cwd === false) {
					throw new LocalizedException('cli.cwd_unavailable');
				}
				$project = $this->absolute($options['project'] ?? '.', $cwd);
				if (in_array($command, ['prepare', 'doctor', 'build'], true) && !is_dir($project)) {
					throw new LocalizedException('cli.project_missing', ['project' => $project]);
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
						throw new LocalizedException('cli.artifact_type_missing', usageError: true);
					}
					if ($artifactType === 'auto') {
						if ($detected === ProjectRole::DUAL->value) {
							throw new LocalizedException('cli.dual_artifact_type_required', usageError: true);
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
						$dualProject = $this->dualArtifactBuilder->prepare($project);
						$checks['structure'] = 'passed';
						$checks['dependencies'] = 'failed';
						$config = $this->configResolver->resolve($project, $dualProject->pluginName);
						$virionsResolved = $this->resolver->resolve($config->virions, $virions);
						$checks['dependencies'] = 'passed';
						$output = $this->absolute($options['out'] ?? 'build', $project);
						$checks['artifact'] = 'failed';
						$artifacts = $this->dualArtifactBuilder->build(
							$dualProject,
							$virions,
							$output,
							$overwrite,
						);
						$data = [
							'project' => $project,
							'artifacts' => [$this->inspector->inspect($artifacts['plugin']), $this->inspector->inspect($artifacts['virion'])],
						];
						$checks['artifact'] = 'passed';
					} else {
						$check = $this->doctor->check($project, $virions, $project . '/plugin.yml');
						$validation = $check->validation;
						foreach ($validation->issues() as $issue) {
							$diagnostics[] = ['code' => $issue->code, 'severity' => $issue->severity->value, 'message' => $issue->message, 'file' => $issue->path, 'line' => null, 'suggestion' => $issue->suggestion];
						}
						$checks['structure'] = $validation->hasErrors() ? 'failed' : 'passed';
						$checks['dependencies'] = $check->dependencyStatus;
						if ($validation->hasErrors()) {
							$exit = 1;
						} elseif ($check->error !== null) {
							throw $check->error;
						} else {
							$manifest = $check->requireManifest();
							$data = [
								'project' => $project,
								'plugin' => ['name' => $manifest->description->getName(), 'version' => $manifest->description->getVersion()],
								'dependencies' => array_map(static fn($virion) : array => ['name' => $virion->manifest->name, 'version' => $virion->manifest->version, 'antigen' => $virion->manifest->antigen, 'source' => $virion->location], $check->virions),
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
			$this->debug?->debug('CLI operation failed: ' . $error->getMessage());
			$exit = $error instanceof InvalidArgumentException || ($error instanceof LocalizedException && $error->isUsageError()) ? 2 : 1;
			$message = $error instanceof LocalizedException ? $error->render($translator) : $error->getMessage();
			$diagnostics[] = [
				'code' => $exit === 2 ? 'cli.usage' : 'operation.' . $command . '_failed',
				'severity' => 'error', 'message' => $message, 'file' => null, 'line' => null,
				'suggestion' => $exit === 2
					? $translator->translate('cli.usage_suggestion')
					: $translator->translate('cli.failure_suggestion'),
			];
		}

		$this->debug?->debug("CLI command {$command} finished with exit code {$exit}.");
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
				fwrite(STDOUT, $command === 'help'
					? self::help($translator)
					: ($command === 'version'
						? $translator->translate('cli.version', ['version' => $this->version]) . "\n"
						: json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"));
				if (!in_array($command, ['help', 'version'], true)) {
					fwrite(STDOUT, $translator->translate('cli.static_notice') . "\n");
				}
			}
		}

		return $exit;
	}

	/** @param list<string> $arguments
	 * @return array{string, array<string, string>}
	 */
	private function parse(array $arguments, string &$reportedCommand) : array
	{
		$command = null;
		$options = [];
		foreach ($arguments as $argument) {
			if ($argument === '--json' || $argument === '--debug') {
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
			if (preg_match('/^--(project|virions|out|artifact|type|format|lang)=(.+)$/Ds', $argument, $match) === 1) {
				if (isset($options[$match[1]]) || str_contains($match[2], "\0") || preg_match('/[\r\n]/', $match[2]) === 1) {
					throw new LocalizedException('cli.path_option_invalid', ['option' => $match[1]], true);
				}
				$options[$match[1]] = $match[2];
				continue;
			}
			if ($command === null && in_array($argument, ['build', 'doctor', 'inspect', 'extract', 'prepare'], true)) {
				$command = $argument;
				$reportedCommand = $command;
				continue;
			}
			throw new LocalizedException('cli.unknown_argument', ['argument' => $argument], true);
		}
		if (isset($options['help']) || $arguments === [] || array_diff($arguments, ['--json', '--debug']) === []) {
			return ['help', $options];
		}
		if (isset($options['version'])) {
			return ['version', $options];
		}
		if ($command === null) {
			throw new LocalizedException('cli.command_required', usageError: true);
		}
		$allowed = match ($command) {
			'build' => ['project', 'virions', 'out', 'overwrite', 'type', 'lang'],
			'doctor', 'prepare' => ['project', 'virions', 'lang'],
			'inspect' => ['project', 'artifact', 'lang'],
			'extract' => ['project', 'artifact', 'out', 'overwrite', 'format', 'type', 'lang'],
		};
		foreach (array_keys($options) as $key) {
			if (!in_array($key, $allowed, true)) {
				throw new LocalizedException('cli.option_unsupported', ['option' => $key, 'command' => $command], true);
			}
		}
		if (isset($options['type'])) {
			$allowedTypes = $command === 'build' ? ['plugin', 'virion', 'both'] : ['auto', 'plugin', 'virion'];
			if (!in_array($options['type'], $allowedTypes, true)) {
				throw new LocalizedException($command === 'build' ? 'cli.build_type_invalid' : 'cli.extract_type_invalid', usageError: true);
			}
		}
		if (isset($options['format']) && !in_array($options['format'], ['folder', 'zip'], true)) {
			throw new LocalizedException('cli.format_invalid', usageError: true);
		}

		return [$command, $options];
	}

	/** @param array<string, string> $options */
	private function required(array $options, string $key) : string
	{
		return $options[$key] ?? throw new LocalizedException('cli.option_required', ['option' => $key], true);
	}

	/** @param array<string, mixed> $inspection */
	private function assertArtifactType(array $inspection, string $type) : void
	{
		if ($type === 'auto') {
			return;
		}
		$detected = $inspection['type'] ?? null;
		if (!is_string($detected)) {
			throw new LocalizedException('cli.artifact_type_missing', usageError: true);
		}
		if (!ArtifactInspector::roleMatches($detected, $type)) {
			throw new LocalizedException('cli.artifact_type_mismatch', ['detected' => $detected, 'type' => $type], true);
		}
	}

	private function absolute(string $path, string $base) : string
	{
		if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
			throw new LocalizedException('cli.local_path_only', usageError: true);
		}
		if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
			return $path;
		}

		return $base . DIRECTORY_SEPARATOR . $path;
	}

	private static function help(Translator $translator) : string
	{
		return $translator->translate('cli.help');
	}
}
