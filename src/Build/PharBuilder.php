<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Validation\PluginManifest;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Validation\PluginProjectValidator;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use FilesystemIterator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use function array_map;
use function array_merge;
use function bin2hex;
use function count;
use function file_exists;
use function hash;
use function implode;
use function in_array;
use function ini_get;
use function is_dir;
use function is_file;
use function ltrim;
use function pathinfo;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function realpath;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

final class PharBuilder
{
	private const NOTICE_FILES = ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'COPYING', 'COPYING.md', 'NOTICE', 'NOTICE.md', 'THIRD_PARTY_NOTICES.md'];

	private readonly PhpSourceInspector $sourceInspector;

	public function __construct(
		private readonly Filesystem $filesystem,
		private readonly PluginProjectValidator $validator,
		private readonly PluginManifestReader $manifestReader,
		private readonly BuildConfigResolver $configResolver,
		private readonly VirionResolver $virionResolver,
		private readonly VirionClassScanner $virionClassScanner,
		private readonly NamespaceShader $namespaceShader,
		?PhpSourceInspector $sourceInspector = null,
		private readonly ?DebugLogger $debug = null,
		private readonly ArtifactSetInstaller $installer = new ArtifactSetInstaller(),
	) {
		$this->sourceInspector = $sourceInspector ?? new PhpSourceInspector();
	}

	public function build(
		string $projectRoot,
		string $virionsDirectory,
		string $outputDirectory,
		bool $overwrite = false,
		?string $outputName = null,
	) : BuildResult {
		$this->debug?->debug("Starting plugin PHAR build for {$projectRoot}.");
		$validation = $this->validator->validate($projectRoot);
		if ($validation->hasErrors()) {
			$messages = [];
			foreach ($validation->issues() as $issue) {
				if ($issue->severity->value === 'error') {
					$messages[] = $issue->format();
				}
			}
			throw new BuildException("Cannot build plugin.\n\n" . implode("\n\n", $messages));
		}
		if ((string) ini_get('phar.readonly') !== '0') {
			throw new BuildException('Cannot build a PHAR because phar.readonly is enabled. Start PHP with -d phar.readonly=0.');
		}

		$manifest = $this->manifestReader->read($projectRoot . DIRECTORY_SEPARATOR . 'plugin.yml');
		$this->assertStandaloneManifest($manifest);
		$config = $this->configResolver->resolve($projectRoot, $manifest->description->getName());
		$virions = $this->virionResolver->resolve($config->virions, $virionsDirectory);
		$this->debug?->debug('Selected ' . count($virions) . " virion(s) for {$manifest->description->getName()}.");
		$shaded = [];
		foreach ($virions as $virion) {
			$shaded[$virion->manifest->antigen] = $this->shadedNamespace($manifest, $virion);
		}
		$dependencies = [];
		foreach ($virions as $virion) {
			$dependency = ['name' => $virion->manifest->name, 'version' => $virion->manifest->version, 'antigen' => $virion->manifest->antigen, 'source' => $virion->location];
			if (is_file($virion->packageRoot . '/devtools-provenance.json')) {
				$dependency['provenance'] = (new ComposerVirionPlan())->readJson($virion->packageRoot . '/devtools-provenance.json');
			}
			$dependencies[] = $dependency;
		}
		$isDevToolsBuild = strtolower($manifest->description->getName()) === 'devtools';
		$availableAntigens = $isDevToolsBuild
			? []
			: array_map(
				static fn(VirionProject $virion) : string => $virion->manifest->antigen,
				$this->virionResolver->discoverValid($virionsDirectory),
			);
		$developmentOnlyNamespaces = $isDevToolsBuild
			? []
			: ['AxolotlBucketLabs\\DevTools', 'poggit\\virion'];
		$forbiddenStandaloneNamespaces = array_merge($availableAntigens, $developmentOnlyNamespaces);

		if (Filesystem::isLink($outputDirectory)) {
			throw new BuildException("Refusing symbolic-link build output directory: {$outputDirectory}");
		}
		$this->filesystem->ensureDirectory($outputDirectory);
		$realProject = realpath($projectRoot);
		$realOutput = realpath($outputDirectory);
		if ($realProject === false || $realOutput === false) {
			throw new BuildException('Cannot resolve the plugin project or build output directory.');
		}
		$sourceRoot = $realProject . DIRECTORY_SEPARATOR . 'src';
		$resourceRoot = $realProject . DIRECTORY_SEPARATOR . 'resources';
		if ($realOutput === $realProject || Path::isInside($sourceRoot, $realOutput) || Path::isInside($resourceRoot, $realOutput)) {
			throw new BuildException(
				"Refusing build output inside plugin source or resources: {$realOutput}. Choose build/ or another separate directory.",
			);
		}
		$artifactName = $outputName ?? $manifest->description->getName();
		if (preg_match('/^[A-Za-z0-9_.-]+$/D', $artifactName) !== 1) {
			throw new BuildException("Cannot derive a safe plugin artifact name from {$artifactName}.");
		}
		$outputPath = Path::join($outputDirectory, $artifactName . '.phar');
		$this->installer->assertDestinations([$outputPath], $overwrite);

		$nonce = bin2hex(random_bytes(8));
		$stage = Path::join($outputDirectory, '.devtools-stage-' . $nonce);
		$temporaryPhar = Path::join($outputDirectory, '.' . $manifest->description->getName() . '.' . $nonce . '.tmp.phar');
		$this->filesystem->ensureDirectory($stage);

		$result = null;
		$failure = null;
		try {
			$this->stagePlugin($projectRoot, $stage, $config, $realOutput);
			$this->assertPluginDeclarationsDoNotOverlapVirions($manifest, $virions, $stage);
			$this->namespaceShader->shadeDirectory($stage, $shaded, false);
			$virionDirectories = $this->stageVirions($virions, $manifest, $stage, $shaded);
			foreach ($virionDirectories as $virionDirectory) {
				$this->namespaceShader->shadeDirectory($virionDirectory, $shaded);
			}
			$this->namespaceShader->assertNoNamespaceReferences($stage, $forbiddenStandaloneNamespaces, $shaded);
			$this->filesystem->assertPortableTree($stage);
			$fileCount = $this->countFiles($stage);
			$this->debug?->debug("Staged {$fileCount} file(s) for {$manifest->description->getName()}.");
			$this->writePhar($stage, $temporaryPhar);
			$this->validatePhar($temporaryPhar, $manifest, $shaded);
			$this->installer->install([['temporary' => $temporaryPhar, 'output' => $outputPath]], $overwrite);

			$result = new BuildResult($manifest->description->getName(), $outputPath, $fileCount, $shaded, $dependencies);
			$this->debug?->debug("Installed plugin PHAR at {$outputPath}.");
		} catch (Throwable $error) {
			$this->debug?->debug('Plugin PHAR build failed: ' . $error->getMessage());
			$failure = $error instanceof BuildException
				? $error
				: new BuildException("Build failed for {$projectRoot}: {$error->getMessage()}", 0, $error);
		}

		$cleanupFailures = [];
		try {
			$this->filesystem->removeTree($stage);
		} catch (Throwable $error) {
			$cleanupFailures[] = $error->getMessage();
		}
		if (!PharSnapshot::remove($temporaryPhar)) {
			$cleanupFailures[] = "Cannot remove temporary PHAR {$temporaryPhar}.";
		}
		if ($failure !== null) {
			if ($cleanupFailures !== []) {
				throw new BuildException(
					$failure->getMessage() . "\nCleanup also failed: " . implode("\n", $cleanupFailures),
					0,
					$failure,
				);
			}
			throw $failure;
		}
		if ($cleanupFailures !== []) {
			throw new BuildException('Build output was created, but cleanup failed: ' . implode("\n", $cleanupFailures));
		}
		return $result;
	}

	private function stagePlugin(string $projectRoot, string $stage, BuildConfig $config, string $realOutput) : void
	{
		$this->filesystem->copyFile(
			$projectRoot . DIRECTORY_SEPARATOR . 'plugin.yml',
			$stage . DIRECTORY_SEPARATOR . 'plugin.yml',
		);
		$source = $projectRoot . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->copyTree(
			$source,
			$stage . DIRECTORY_SEPARATOR . 'src',
			static fn(string $relative) : bool => !Path::isHiddenOrTemporary($relative),
		);

		$resources = $projectRoot . DIRECTORY_SEPARATOR . 'resources';
		if (is_dir($resources)) {
			$this->filesystem->copyTree(
				$resources,
				$stage . DIRECTORY_SEPARATOR . 'resources',
				static fn(string $relative) : bool => !Path::isHiddenOrTemporary($relative),
			);
		}

		foreach (self::NOTICE_FILES as $notice) {
			$sourceNotice = $projectRoot . DIRECTORY_SEPARATOR . $notice;
			if (is_file($sourceNotice)) {
				$this->filesystem->copyFile($sourceNotice, $stage . DIRECTORY_SEPARATOR . $notice);
			}
		}

		foreach ($config->includePaths as $relative) {
			$sourcePath = Path::join($projectRoot, $relative);
			try {
				$this->filesystem->assertNoSymbolicLinkComponents($projectRoot, $relative);
			} catch (\RuntimeException $error) {
				throw new BuildException("Declared include path {$relative} is unsafe: {$error->getMessage()}", 0, $error);
			}
			$realSource = realpath($sourcePath);
			$realProject = realpath($projectRoot);
			if ($realSource === false || $realProject === false || !Path::isInside($realProject, $realSource)) {
				throw new BuildException("Declared include path {$relative} is missing or outside {$projectRoot}.");
			}
			if (Path::isInside($realSource, $realOutput) || Path::isInside($realOutput, $realSource)) {
				throw new BuildException(
					"Declared include path {$relative} overlaps the build output {$realOutput}. This would make staging recursive or unstable.",
				);
			}
			$destination = Path::join($stage, $relative);
			if (is_dir($realSource)) {
				$this->filesystem->copyTree(
					$realSource,
					$destination,
					static function (string $child) : bool {
						if (Path::isSensitive($child)) {
							throw new BuildException(
								"Refusing sensitive file inside a declared include path: {$child}",
							);
						}

						return !Path::isHiddenOrTemporary($child);
					},
				);
			} elseif (is_file($realSource)) {
				$this->filesystem->copyFile($realSource, $destination);
			} else {
				throw new BuildException("Declared include path is not a regular file or directory: {$relative}");
			}
		}
	}

	/**
	 * @param list<VirionProject>   $virions
	 * @param array<string, string> $shaded  Original antigen => shaded namespace.
	 *
	 * @return list<string> Staged virion source directories.
	 */
	private function stageVirions(array $virions, PluginManifest $plugin, string $stage, array $shaded) : array
	{
		$usedAntigens = [];
		$directories = [];
		foreach ($virions as $virion) {
			$antigenKey = strtolower($virion->manifest->antigen);
			foreach ($usedAntigens as $usedAntigen) {
				if ($this->isNamespaceMember($virion->manifest->antigen, $usedAntigen)
					|| $this->isNamespaceMember($usedAntigen, $virion->manifest->antigen)) {
					throw new BuildException(
						"Virion antigen {$virion->manifest->antigen} overlaps declared antigen {$usedAntigen}. Shading would be ambiguous.",
					);
				}
			}
			$usedAntigens[$antigenKey] = $virion->manifest->antigen;

			$classes = $this->virionClassScanner->scan($virion);
			if ($classes === []) {
				throw new BuildException("Virion {$virion->manifest->name} contains no PHP classes.");
			}
			foreach ($classes as $class) {
				$prefix = $virion->manifest->antigen;
				if (!str_starts_with(strtolower($class), strtolower($prefix) . '\\')) {
					throw new BuildException("Virion {$virion->manifest->name} declares class {$class} outside antigen {$prefix}.");
				}
			}

			$targetNamespace = $shaded[$virion->manifest->antigen];
			$targetRelative = $targetNamespace;
			$sourcePrefix = trim($plugin->description->getSrcNamespacePrefix(), '\\');
			if ($sourcePrefix !== '') {
				$targetRelative = substr($targetNamespace, strlen($sourcePrefix) + 1);
			}
			$target = $stage . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $targetRelative);
			if (file_exists($target)) {
				throw new BuildException("Shaded virion output collides with an existing source path: {$target}");
			}
			$this->filesystem->copyTree(
				$virion->sourceRoot,
				$target,
				static fn(string $relative) : bool => !Path::isHiddenOrTemporary($relative),
			);
			$this->stageVirionResources($virion, $stage);
			$directories[] = $target;
		}

		return $directories;
	}

	/** @param list<VirionProject> $virions */
	private function assertPluginDeclarationsDoNotOverlapVirions(PluginManifest $plugin, array $virions, string $sourceDirectory) : void
	{
		$main = trim($plugin->description->getMain(), '\\');
		$separator = strrpos($main, '\\');
		if ($separator !== false) {
			$pluginNamespace = substr($main, 0, $separator);
			foreach ($virions as $virion) {
				if ($this->isNamespaceMember($pluginNamespace, $virion->manifest->antigen)) {
					throw new BuildException(
						"Plugin namespace {$pluginNamespace} overlaps virion antigen {$virion->manifest->antigen}. "
						. 'Choose a plugin namespace outside the virion antigen before building a shaded PHAR.',
					);
				}
			}
		}
		if (!is_dir($sourceDirectory)) {
			return;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
		);
		foreach ($files as $file) {
			if (!$file instanceof SplFileInfo || !$file->isFile() || !$this->isPhpSourceFile($file->getFilename())) {
				continue;
			}
			$symbols = $this->sourceInspector->inspect($file->getPathname());
			foreach (array_merge($symbols->classes, $symbols->functions, $symbols->constants) as $declaration) {
				$separator = strrpos($declaration, '\\');
				if ($separator === false) {
					continue;
				}
				$namespace = substr($declaration, 0, $separator);
				foreach ($virions as $virion) {
					if (!$this->isNamespaceMember($namespace, $virion->manifest->antigen)) {
						continue;
					}
					throw new BuildException(
						"Plugin declaration {$declaration} overlaps virion antigen {$virion->manifest->antigen}. "
						. 'Choose a plugin declaration namespace outside the virion antigen before building a shaded PHAR.',
					);
				}
			}
		}
	}

	private function isPhpSourceFile(string $filename) : bool
	{
		return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['php', 'inc', 'phtml'], true);
	}

	private function shadedNamespace(PluginManifest $plugin, VirionProject $virion) : string
	{
		$sourcePrefix = trim($plugin->description->getSrcNamespacePrefix(), '\\');
		$main = trim($plugin->description->getMain(), '\\');
		$separator = strrpos($main, '\\');
		$pluginNamespace = $separator === false ? '' : substr($main, 0, $separator);
		$base = $sourcePrefix !== '' ? $sourcePrefix : $pluginNamespace;
		if ($base === '') {
			$base = 'DevToolsShade\\' . preg_replace('/^(?=[0-9])|[^A-Za-z0-9_]/', '_', $plugin->description->getName());
		}
		$name = preg_replace('/^(?=[0-9])|[^A-Za-z0-9_]/', '_', $virion->manifest->name);
		$digest = substr(hash('sha256', strtolower($plugin->description->getName() . '|' . $virion->manifest->name . '|' . $virion->manifest->antigen)), 0, 12);

		return $base . '\\_DevTools\\' . $name . '_' . $digest;
	}

	private function stageVirionResources(VirionProject $virion, string $stage) : void
	{
		$resources = $virion->packageRoot . '/resources';
		if (is_dir($resources) && (new FilesystemIterator($resources, FilesystemIterator::SKIP_DOTS))->valid()) {
			throw new BuildException(
				"Virion {$virion->manifest->name} has root resources/. Only resources colocated under its source directory retain relative paths when bundled.",
			);
		}
		$metadata = $stage . '/META-INF/virions/' . $virion->manifest->name;
		if (file_exists($metadata)) {
			throw new BuildException("Virion metadata output collides with an existing plugin path: {$metadata}");
		}
		foreach ([...self::NOTICE_FILES, 'devtools-provenance.json'] as $license) {
			$source = $virion->packageRoot . '/' . $license;
			if (is_file($source)) {
				$this->filesystem->copyFile($source, $metadata . '/' . $license);
			}
		}
	}

	private function assertStandaloneManifest(PluginManifest $manifest) : void
	{
		$dependencies = array_merge(
			$manifest->description->getDepend(),
			$manifest->description->getSoftDepend(),
			$manifest->description->getLoadBefore(),
		);
		foreach ($dependencies as $dependency) {
			if (in_array(strtolower($dependency), ['devtools', 'devirion'], true)) {
				throw new BuildException(
					"Standalone builds cannot retain a {$dependency} manifest dependency. Remove it and declare release virions in devtools.yml.",
				);
			}
		}
	}

	private function isNamespaceMember(string $candidate, string $prefix) : bool
	{
		$candidate = strtolower(ltrim($candidate, '\\'));
		$prefix = strtolower(ltrim($prefix, '\\'));

		return $candidate === $prefix || str_starts_with($candidate, $prefix . '\\');
	}

	private function writePhar(string $stage, string $path) : void
	{
		$phar = new Phar($path);
		$phar->startBuffering();
		$phar->buildFromDirectory($stage);
		$phar->setStub("<?php __HALT_COMPILER();\n");
		$phar->setSignatureAlgorithm(Phar::SHA256);
		$phar->stopBuffering();
		unset($phar);
	}

	/** @param array<string, string> $shaded */
	private function validatePhar(string $path, PluginManifest $manifest, array $shaded) : void
	{
		$phar = new Phar($path);
		if (!isset($phar['plugin.yml'])) {
			throw new BuildException('Generated PHAR is invalid: plugin.yml is missing.');
		}
		$mainRelative = $manifest->expectedMainRelativePath();
		if ($mainRelative === null || !isset($phar['src/' . $mainRelative])) {
			throw new BuildException('Generated PHAR is invalid: the main class source file is missing.');
		}
		$mainPath = 'phar://' . str_replace('\\', '/', $path) . '/src/' . $mainRelative;
		try {
			$symbols = $this->sourceInspector->inspect($mainPath);
		} catch (Throwable $error) {
			throw new BuildException("Generated PHAR main source could not be inspected: {$error->getMessage()}", 0, $error);
		}
		$main = $manifest->description->getMain();
		if (!in_array($main, $symbols->classes, true)) {
			$found = $symbols->classes === [] ? 'no class declaration' : implode(', ', $symbols->classes);
			throw new BuildException("Generated PHAR main source declares {$found}, expected {$main}.");
		}
		foreach ($shaded as $target) {
			$sourcePrefix = trim($manifest->description->getSrcNamespacePrefix(), '\\');
			$relative = $sourcePrefix === '' ? $target : substr($target, strlen($sourcePrefix) + 1);
			$directory = 'src/' . str_replace('\\', '/', $relative);
			if (!isset($phar[$directory])) {
				throw new BuildException("Generated PHAR is invalid: shaded virion directory {$directory} is missing.");
			}
		}
		$signature = $phar->getSignature();
		if ($signature['hash_type'] !== 'SHA-256') {
			throw new BuildException('Generated PHAR has no SHA-256 signature.');
		}
		unset($phar);
	}

	private function countFiles(string $directory) : int
	{
		$count = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo) {
				continue;
			}
			if ($entry->isFile()) {
				++$count;
			}
		}

		return $count;
	}
}
