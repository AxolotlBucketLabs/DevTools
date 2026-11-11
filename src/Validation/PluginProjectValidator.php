<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use function explode;
use function file_exists;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_split;
use function scandir;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;
use const DIRECTORY_SEPARATOR;

final class PluginProjectValidator
{
	public function __construct(
		private readonly PluginManifestReader $manifestReader,
		private readonly PhpSourceInspector $sourceInspector,
	) {}

	public function validate(string $projectRoot) : ValidationResult
	{
		$result = new ValidationResult();
		if (!is_dir($projectRoot)) {
			$result->error('project.missing', "The plugin folder {$projectRoot} does not exist.", $projectRoot, 'Choose a folder under plugins/.');

			return $result;
		}
		if (Filesystem::isLink($projectRoot)) {
			$result->error('project.symlink', 'Symbolic-link plugin roots are not loaded.', $projectRoot, 'Use a real directory under plugins/.');

			return $result;
		}

		$manifestPath = $projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::PLUGIN_MANIFEST;
		if (!is_file($manifestPath)) {
			$result->error(
				'plugin.manifest_missing',
				'This folder looks like a plugin project but plugin.yml is missing.',
				$manifestPath,
				'Create plugin.yml with name, version, main, and api fields.',
			);

			return $result;
		}
		if (Filesystem::isLink($manifestPath)) {
			$result->error('plugin.manifest_symlink', 'plugin.yml may not be a symbolic link.', $manifestPath, 'Copy the manifest into the project directory.');

			return $result;
		}

		$contents = file_get_contents($manifestPath);
		if ($contents !== false) {
			$this->checkDuplicateSections($contents, $manifestPath, $result);
		}

		try {
			$manifest = $this->manifestReader->read($manifestPath);
		} catch (\RuntimeException $error) {
			$result->error(
				'plugin.manifest_invalid',
				$error->getMessage(),
				$manifestPath,
				'Correct the YAML syntax and required manifest fields.',
			);

			return $result;
		}

		$src = $projectRoot . DIRECTORY_SEPARATOR . ArtifactFile::SOURCE_DIRECTORY;
		if (!is_dir($src)) {
			$result->error('plugin.src_missing', 'The plugin source directory is missing.', $src, 'Create src/ and place the main class in it.');

			return $result;
		}
		if (Filesystem::isLink($src)) {
			$result->error('plugin.src_symlink', 'The plugin source directory may not be a symbolic link.', $src, 'Copy source files into the project src/ directory.');

			return $result;
		}

		$main = $manifest->description->getMain();
		if (preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $main) !== 1) {
			$result->error('plugin.main_invalid', "plugin.yml declares an invalid main class: {$main}", $manifestPath, 'Use a fully qualified PHP class name.');

			return $result;
		}

		$relative = $manifest->expectedMainRelativePath();
		if ($relative === null) {
			$prefix = $manifest->description->getSrcNamespacePrefix();
			$result->error(
				'plugin.namespace_prefix_mismatch',
				"The main class {$main} is outside src-namespace-prefix {$prefix}.",
				$manifestPath,
				'Make main begin with the namespace prefix, or remove src-namespace-prefix.',
			);

			return $result;
		}

		$expected = $src . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
		$linkedComponent = $this->linkedPathComponent($src, $relative);
		if ($linkedComponent !== null) {
			$result->error(
				'plugin.main_symlink',
				"The main source path traverses symbolic link {$linkedComponent}.",
				$linkedComponent,
				'Copy the source into the project instead of linking it.',
			);

			return $result;
		}
		$caseMatch = $this->caseSensitivePathExists($src, $relative);
		if ($caseMatch === false) {
			if (is_file($expected)) {
				$result->error(
					'plugin.main_case_mismatch',
					"The main source path has different letter casing from {$relative}.",
					$expected,
					'Rename each directory and file so its case exactly matches the namespace.',
				);
			} else {
				$result->error(
					'plugin.main_missing',
					"Cannot find the source file for main class {$main}.",
					$expected,
					"Correct the main field or create src/{$relative}.",
				);
			}

			return $result;
		}

		try {
			$symbols = $this->sourceInspector->inspect($expected);
			if (!in_array($main, $symbols->classes, true)) {
				$found = $symbols->classes === [] ? 'no class declaration' : implode(', ', $symbols->classes);
				$result->error(
					'plugin.main_declaration_mismatch',
					"Expected class {$main}, but the file declares {$found}.",
					$expected,
					'Correct the namespace and class declaration or plugin.yml main field.',
				);
			}
		} catch (\RuntimeException $error) {
			$result->error('plugin.source_invalid', $error->getMessage(), $expected, 'Correct the PHP syntax before loading or building.');
		}

		$api = $manifest->raw['api'] ?? null;
		$apiValues = is_array($api) ? $api : [$api];
		if ($apiValues === []) {
			$result->error('plugin.api_invalid', 'The api field must contain at least one Axolotl-PM API version.', $manifestPath, 'Declare a version such as api: 5.0.0.');
		}
		foreach ($apiValues as $apiValue) {
			if (!is_string($apiValue) || preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$/D', $apiValue) !== 1) {
				$result->error('plugin.api_invalid', 'The api field must contain Axolotl-PM API version strings.', $manifestPath, 'Use a version such as api: 5.0.0 or a list of versions supported by your target server.');
				break;
			}
		}

		$resources = $projectRoot . DIRECTORY_SEPARATOR . 'resources';
		if (Filesystem::isLink($resources)) {
			$result->error('plugin.resources_symlink', 'resources may not be a symbolic link.', $resources, 'Copy resources into the project resources/ directory.');
		} elseif (file_exists($resources) && !is_dir($resources)) {
			$result->error('plugin.resources_invalid', 'resources exists but is not a directory.', $resources, 'Replace it with a resources/ directory.');
		}

		$this->checkDependencyLists($manifest, $result);

		return $result;
	}

	private function checkDependencyLists(PluginManifest $manifest, ValidationResult $result) : void
	{
		foreach (['depend', 'softdepend', 'loadbefore'] as $field) {
			if (!isset($manifest->raw[$field])) {
				continue;
			}
			$values = is_array($manifest->raw[$field]) ? $manifest->raw[$field] : [$manifest->raw[$field]];
			$seen = [];
			foreach ($values as $value) {
				if (!is_string($value) || $value === '') {
					$result->error('plugin.dependency_invalid', "{$field} must contain non-empty plugin names.", $manifest->path, "Correct the {$field} declaration.");
					continue;
				}
				$key = strtolower($value);
				if (isset($seen[$key])) {
					$result->warning('plugin.dependency_duplicate', "{$field} declares {$value} more than once.", $manifest->path, 'Remove the duplicate entry.');
				}
				$seen[$key] = true;
			}
		}
	}

	private function caseSensitivePathExists(string $base, string $relative) : bool
	{
		$current = $base;
		foreach (explode('/', $relative) as $component) {
			if (!is_dir($current)) {
				return false;
			}
			$entries = scandir($current);
			if ($entries === false || !in_array($component, $entries, true)) {
				return false;
			}
			$current .= DIRECTORY_SEPARATOR . $component;
		}

		return is_file($current);
	}

	private function linkedPathComponent(string $base, string $relative) : ?string
	{
		$current = $base;
		foreach (explode('/', $relative) as $component) {
			$current .= DIRECTORY_SEPARATOR . $component;
			if (Filesystem::isLink($current)) {
				return $current;
			}
		}

		return null;
	}

	private function checkDuplicateSections(string $yaml, string $path, ValidationResult $result) : void
	{
		$lines = preg_split('/\R/', $yaml) ?: [];
		$section = null;
		$sectionIndent = 0;
		$childIndent = null;
		$seen = [];
		$seenRootSections = [];
		foreach ($lines as $line) {
			if (preg_match('/^(\s*)(commands|permissions)\s*:\s*(?:#.*)?$/', $line, $match) === 1) {
				$section = $match[2];
				$sectionIndent = strlen($match[1]);
				if ($sectionIndent === 0) {
					if (isset($seenRootSections[$section])) {
						$result->error(
							"plugin.duplicate_{$section}",
							"The root {$section} section is declared more than once.",
							$path,
							"Keep only one root {$section} section.",
						);
					}
					$seenRootSections[$section] = true;
				}
				$childIndent = null;
				$seen = [];
				continue;
			}
			if ($section === null || trim($line) === '' || str_starts_with(ltrim($line), '#')) {
				continue;
			}
			if (preg_match('/^(\s*)([^:#][^:]*)\s*:/', $line, $match) !== 1) {
				continue;
			}
			$indent = strlen($match[1]);
			if ($indent <= $sectionIndent) {
				$section = null;
				continue;
			}
			$childIndent ??= $indent;
			if ($indent !== $childIndent) {
				continue;
			}
			$key = strtolower(trim($match[2], " \t'\""));
			if (isset($seen[$key])) {
				$result->error("plugin.duplicate_{$section}", "The {$section} section declares {$key} more than once.", $path, 'Remove or rename the duplicate key.');
			}
			$seen[$key] = true;
		}
	}
}
