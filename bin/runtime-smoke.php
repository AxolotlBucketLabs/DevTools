<?php

declare(strict_types=1);

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;

const PROCESS_POLL_INTERVAL_MICROSECONDS = 100000;
const PROCESS_STOP_GRACE_PERIOD_MICROSECONDS = 500000;
const RUNTIME_SMOKE_JSON_DEPTH = 512;
const DIRECTORY_CREATE_PERMISSIONS = 0777;
const GIT_COMMIT_REFERENCE_LENGTH = 40;
const BUILD_PROCESS_TIMEOUT_SECONDS = 60;
const SERVER_PROCESS_TIMEOUT_SECONDS = 90;
const SERVER_ARGUMENT_PREFIX = '--server=';
const LOCKED_SERVER_SOURCE_DIRECTORIES = [ArtifactFile::SOURCE_DIRECTORY, ArtifactFile::GENERATED_DIRECTORY, ArtifactFile::RESOURCE_DIRECTORY];
const TEXT_FILE_EXTENSIONS_WITH_NORMALIZED_LINE_ENDINGS = ['md', 'json', 'yml'];
const SERVER_ENTRYPOINT = ArtifactFile::SOURCE_DIRECTORY . '/PocketMine' . ArtifactFile::PHP_SOURCE_EXTENSION;

/**
 * @param list<string> $command
 */
function smokeProcess(array $command, string $directory, string $log, int $timeout) : void
{
	$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.err', 'w']], $pipes, $directory, null, ['bypass_shell' => true]);
	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start smoke-test process.');
	}
	$deadline = time() + $timeout;
	do {
		usleep(PROCESS_POLL_INTERVAL_MICROSECONDS);
		$status = proc_get_status($process);
		if (!$status['running']) {
			break;
		}
	} while (time() < $deadline);
	if ($status['running']) {
		fwrite($pipes[0], "stop\n");
		usleep(PROCESS_STOP_GRACE_PERIOD_MICROSECONDS);
		proc_terminate($process);
	}
	fclose($pipes[0]);
	proc_close($process);
	if ($status['running'] || $status['exitcode'] !== 0) {
		throw new RuntimeException("Process failed or exceeded {$timeout}s. See {$log} and {$log}.err.");
	}
}

function smokeWrite(string $path, string $contents) : void
{
	if (!is_dir(dirname($path)) && !mkdir(dirname($path), DIRECTORY_CREATE_PERMISSIONS, true)) {
		throw new RuntimeException("Cannot create directory for {$path}.");
	}
	if (file_put_contents($path, $contents) === false) {
		throw new RuntimeException("Cannot write {$path}.");
	}
}

function smokeRead(string $path) : string
{
	$contents = file_get_contents($path);
	if ($contents === false) {
		throw new RuntimeException("Cannot read {$path}.");
	}

	return $contents;
}

function smokePlugin(string $root, string $name, string $other, string $version) : void
{
	$fixture = dirname(__DIR__) . '/tests/Fixtures/RuntimeSmoke';
	smokeWrite($root . '/' . ArtifactFile::PLUGIN_MANIFEST, "name: {$name}\nversion: 1.0.0\nmain: RuntimeSmoke\\{$name}\\Main\napi: 5.0.0\nsrc-namespace-prefix: RuntimeSmoke\\{$name}\n");
	if ($name === 'SmokeA') {
		file_put_contents($root . '/' . ArtifactFile::PLUGIN_MANIFEST, "depend: [SmokeB]\n", FILE_APPEND);
	}
	smokeWrite($root . '/' . ArtifactFile::DEVTOOLS_CONFIG, "virions:\n  - name: SmokeLibrary\n    version: ^{$version}.0.0\n");
	smokeWrite($root . '/' . ArtifactFile::RESOURCE_DIRECTORY . '/probe.txt', 'resource-ok');
	foreach (['Main', 'AsyncProbe'] as $class) {
		smokeWrite($root . '/' . ArtifactFile::SOURCE_DIRECTORY . '/' . $class . ArtifactFile::PHP_SOURCE_EXTENSION, strtr(smokeRead($fixture . '/' . $class . '.php.tpl'), ['{{PLUGIN}}' => $name, '{{OTHER}}' => $other, '{{VERSION}}' => $version]));
	}
}

function smokeVirion(string $root, string $version) : void
{
	smokeWrite($root . '/' . ArtifactFile::VIRION_MANIFEST, "name: SmokeLibrary\nversion: {$version}.0.0\nantigen: RuntimeSmoke\\Library\napi: 5.0.0\n");
	smokeWrite($root . '/' . ArtifactFile::SOURCE_DIRECTORY . '/Value' . ArtifactFile::PHP_SOURCE_EXTENSION, strtr(smokeRead(dirname(__DIR__) . '/tests/Fixtures/RuntimeSmoke/Value.php.tpl'), ['{{VERSION}}' => $version]));
}

function smokeComposerPlugin(string $root, bool $transitive) : void
{
	unlink($root . '/' . ArtifactFile::DEVTOOLS_CONFIG);
	$composer = ['name' => 'smoke/plugin', 'require' => ['smoke/library' => '^1.0']];
	smokeWrite($root . '/' . ArtifactFile::COMPOSER_MANIFEST, json_encode($composer, JSON_THROW_ON_ERROR));
	ksort($composer);
	smokeWrite($root . '/' . ArtifactFile::COMPOSER_LOCK, json_encode([
		'content-hash' => hash(AxolotlBucketLabs\DevTools\Support\HashAlgorithm::MD5, json_encode($composer, JSON_THROW_ON_ERROR)),
		'packages' => array_values(smokeComposerPackages($transitive)),
	], JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, array<string, mixed>>
 */
function smokeComposerPackages(bool $transitive) : array
{
	$packages = ['smoke/library' => [
		'name' => 'smoke/library', 'version' => '1.0.0',
		'autoload' => ['psr-4' => ['RuntimeSmoke\\Library\\' => ArtifactFile::SOURCE_DIRECTORY . '/']],
		'require' => $transitive ? ['smoke/helper' => '^1.0'] : [],
		'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'RuntimeSmoke\\Library']],
	]];
	if ($transitive) {
		$packages['smoke/helper'] = [
			'name' => 'smoke/helper', 'version' => '1.0.0',
			'autoload' => ['psr-4' => ['RuntimeSmoke\\Helper\\' => ArtifactFile::SOURCE_DIRECTORY . '/']],
			'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'RuntimeSmoke\\Helper']],
		];
	}
	return $packages;
}

function smokeComposerVirions(string $data, string $mode) : void
{
	$mixed = $mode !== 'shared-composer-folder';
	$library = $data . '/virions/SmokeLibrary';
	smokeVirion($library, '1');
	$packages = smokeComposerPackages($mixed);
	smokeWrite($library . '/' . ArtifactFile::COMPOSER_MANIFEST, json_encode($packages['smoke/library'], JSON_THROW_ON_ERROR));
	if (!$mixed) {
		return;
	}
	smokeWrite($library . '/' . ArtifactFile::VIRION_MANIFEST, smokeRead($library . '/' . ArtifactFile::VIRION_MANIFEST) . "virions: [{name: smoke.helper, version: '1.0.0'}]\n");
	$libraryValue = ArtifactFile::SOURCE_DIRECTORY . '/Value' . ArtifactFile::PHP_SOURCE_EXTENSION;
	smokeWrite($library . '/' . $libraryValue, str_replace("return '1';", 'return \\RuntimeSmoke\\Helper\\Value::version();', smokeRead($library . '/' . $libraryValue)));
	$helper = $data . '/virions/SmokeHelper';
	smokeWrite($helper . '/' . ArtifactFile::VIRION_MANIFEST, "name: SmokeHelper\nversion: 1.0.0\nantigen: RuntimeSmoke\\Helper\napi: 5.0.0\n");
	smokeWrite($helper . '/' . ArtifactFile::SOURCE_DIRECTORY . '/Value' . ArtifactFile::PHP_SOURCE_EXTENSION, "<?php namespace RuntimeSmoke\\Helper; final class Value { public static function version(): string { return '1'; } }\n");
	smokeWrite($helper . '/' . ArtifactFile::COMPOSER_MANIFEST, json_encode($packages['smoke/helper'], JSON_THROW_ON_ERROR));
	$project = $data . '/plugins/SmokeA';
	$filesystem = new AxolotlBucketLabs\DevTools\Support\Filesystem();
	foreach (['smoke/library' => $library, 'smoke/helper' => $helper] as $name => $source) {
		$filesystem->copyTree($source, $project . '/' . ArtifactFile::COMPOSER_VENDOR_DIRECTORY . '/' . $name);
	}
	smokeWrite($project . '/' . ArtifactFile::COMPOSER_VENDOR_DIRECTORY . '/' . ArtifactFile::COMPOSER_INSTALLED_METADATA, json_encode(['packages' => array_values($packages)], JSON_THROW_ON_ERROR));
	(new AxolotlBucketLabs\DevTools\Build\ComposerVirionPreparer($filesystem))->prepare($project, $data . '/prepared');
	$preparedName = $mode === 'shared-composer-mixed' ? 'smoke.library' : 'smoke.helper';
	$source = $mode === 'shared-composer-mixed' ? $library : $helper;
	if ($mode === 'shared-composer-local-parent') {
	$virionManifest = $library . '/' . ArtifactFile::VIRION_MANIFEST;
	smokeWrite($virionManifest, str_replace('name: smoke.helper', 'name: SmokeHelper', smokeRead($virionManifest)));
	} else {
		$filesystem->removeTree($source);
	}
	$filesystem->copyTree($data . '/prepared/' . $preparedName, $data . '/virions/' . $preparedName);
}

try {
	$root = dirname(__DIR__);
	$server = null;
	foreach (array_slice($argv ?? [], 1) as $argument) {
		if (str_starts_with($argument, SERVER_ARGUMENT_PREFIX)) {
			$server = realpath(substr($argument, strlen(SERVER_ARGUMENT_PREFIX)));
		} elseif ($argument === '--help') {
			fwrite(STDOUT, "Usage: php -d phar.readonly=0 bin/runtime-smoke.php --server=/path/to/prepared/axolotl\nCreates fresh data directories under build/runtime-smoke-*. Requires the exact Axolotl-PM source from composer.lock and its installed dependencies. No downloads are performed.\n");
			exit(0);
		} else {
			throw new RuntimeException("Unknown argument: {$argument}");
		}
	}
	if (!is_string($server) || !is_file($server . '/' . ArtifactFile::COMPOSER_VENDOR_DIRECTORY . '/' . ArtifactFile::COMPOSER_AUTOLOAD)) {
		throw new RuntimeException('Pass --server=<prepared Axolotl-PM source directory> with its own installed vendor dependencies.');
	}
	if (!extension_loaded('pmmpthread') || ini_get('phar.readonly') !== '0') {
		throw new RuntimeException('Use the Axolotl-PM-compatible PHP binary with pmmpthread and -d phar.readonly=0.');
	}
	require $root . '/' . ArtifactFile::COMPOSER_VENDOR_DIRECTORY . '/' . ArtifactFile::COMPOSER_AUTOLOAD;
	$reference = Composer\InstalledVersions::getReference('axolotl-pm/pocketmine-mp');
	$installed = Composer\InstalledVersions::getInstallPath('axolotl-pm/pocketmine-mp');
	if ($reference === null || $installed === null) {
		throw new RuntimeException('Install DevTools development dependencies from composer.lock first.');
	}
		$lock = json_decode(smokeRead($root . '/' . ArtifactFile::COMPOSER_LOCK), true, RUNTIME_SMOKE_JSON_DEPTH, JSON_THROW_ON_ERROR);
	if (!is_array($lock) || !is_array($lock['packages-dev'] ?? null)) {
		throw new RuntimeException('DevTools composer.lock has no development package list.');
	}
	$expectedReference = null;
	foreach ($lock['packages-dev'] as $package) {
		if (is_array($package) && ($package['name'] ?? null) === 'axolotl-pm/pocketmine-mp') {
			$source = $package['source'] ?? null;
			if (is_array($source)) {
				$expectedReference = $source['reference'] ?? null;
			}
			break;
		}
	}
	if (!is_string($expectedReference) || strlen($expectedReference) !== GIT_COMMIT_REFERENCE_LENGTH || preg_match('/^[a-f0-9]+$/', $expectedReference) !== 1) {
		throw new RuntimeException('DevTools composer.lock has no valid Axolotl-PM source commit.');
	}
	if ($reference !== $expectedReference) {
		throw new RuntimeException('Installed Axolotl-PM source differs from DevTools composer.lock.');
	}
	foreach (LOCKED_SERVER_SOURCE_DIRECTORIES as $directory) {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($installed . '/' . $directory, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
				continue;
			}
			$relative = substr($entry->getPathname(), strlen($installed) + 1);
			$serverFile = $server . '/' . $relative;
			if (!is_file($serverFile)) {
				throw new RuntimeException("Server differs from locked Axolotl-PM {$reference}: {$relative}");
			}
			if (hash_file(AxolotlBucketLabs\DevTools\Support\HashAlgorithm::SHA256, $entry->getPathname()) !== hash_file(AxolotlBucketLabs\DevTools\Support\HashAlgorithm::SHA256, $serverFile)) {
				$extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
				if (!in_array($extension, TEXT_FILE_EXTENSIONS_WITH_NORMALIZED_LINE_ENDINGS, true) || str_replace("\r\n", "\n", smokeRead($entry->getPathname())) !== str_replace("\r\n", "\n", smokeRead($serverFile))) {
					throw new RuntimeException("Server differs from locked Axolotl-PM {$reference}: {$relative}");
				}
			}
		}
	}
	if (str_replace("\r\n", "\n", smokeRead($server . '/' . ArtifactFile::COMPOSER_LOCK)) !== str_replace("\r\n", "\n", smokeRead($installed . '/' . ArtifactFile::COMPOSER_LOCK))) {
		throw new RuntimeException('Prepared server composer.lock differs from the locked Axolotl-PM source.');
	}
	$run = $root . '/build/runtime-smoke-' . date('Ymd-His') . '-' . AxolotlBucketLabs\DevTools\Support\RandomToken::pathSuffix();
	smokeWrite($run . '/target.json', json_encode(['axolotl' => $reference, 'php' => PHP_VERSION, 'pmmpthread' => phpversion('pmmpthread')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	smokeProcess([PHP_BINARY, '-d', 'phar.readonly=0', $root . '/bin/devtools-build.php', '--project=' . $root, '--out=' . $run . '/tools'], $root, $run . '/build-devtools.log', BUILD_PROCESS_TIMEOUT_SECONDS);
	$passed = [];
	foreach (['shared-folder', 'shared-phar', 'shared-composer-folder', 'shared-composer-mixed', 'shared-composer-local-parent', 'private-phar'] as $mode) {
		$data = $run . '/' . $mode;
		smokeWrite($data . '/server.properties', 'language=' . AxolotlBucketLabs\DevTools\Support\Translator::DEFAULT_LOCALE . "\nserver-ip=127.0.0.1\nserver-port=0\nenable-ipv6=off\nenable-query=off\nxbox-auth=off\nlevel-type=FLAT\nview-distance=2\nmax-players=1\n");
		smokeWrite($data . '/pocketmine.yml', "settings:\n  async-workers: 2\n  enable-dev-builds: true\n  send-usage: false\nauto-report:\n  enabled: false\nauto-updater:\n  enabled: false\nnetwork:\n  upnp-forwarding: false\ntransport:\n  name: raknet\n");
		$private = $mode === 'private-phar';
		$plain = $private ? $run . '/inputs/SmokePlain' : $data . '/plugins/SmokePlain';
		smokeWrite($plain . '/' . ArtifactFile::PLUGIN_MANIFEST, "name: SmokePlain\nversion: 1.0.0\nmain: RuntimeSmoke\\Plain\\Main\napi: 5.0.0\nsrc-namespace-prefix: RuntimeSmoke\\Plain\n");
		smokeWrite($plain . '/src/Main.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace RuntimeSmoke\Plain;
final class Main extends \pocketmine\plugin\PluginBase {
    protected function onEnable(): void {
        file_put_contents($this->getServer()->getDataPath() . 'SmokePlain.passed', 'passed');
    }
}
PHP);
		if ($private) {
			smokeProcess([PHP_BINARY, '-d', 'phar.readonly=0', $root . '/bin/devtools-build.php', '--project=' . $plain, '--out=' . $data . '/plugins'], $root, $data . '/build-SmokePlain.log', BUILD_PROCESS_TIMEOUT_SECONDS);
		}
		foreach (['SmokeA' => 'SmokeB', 'SmokeB' => 'SmokeA'] as $name => $other) {
			$version = $private && $name === 'SmokeB' ? '2' : '1';
			$project = $private ? $run . '/inputs/' . $name : $data . '/plugins/' . $name;
			smokePlugin($project, $name, $other, $version);
			if (str_starts_with($mode, 'shared-composer-')) {
				smokeComposerPlugin($project, $mode !== 'shared-composer-folder');
			}
			if ($private) {
				$virions = $run . '/inputs/virions-' . $version;
				smokeVirion($virions . '/SmokeLibrary', $version);
				smokeProcess([PHP_BINARY, '-d', 'phar.readonly=0', $root . '/bin/devtools-build.php', '--project=' . $project, '--virions=' . $virions, '--out=' . $data . '/plugins'], $root, $data . '/build-' . $name . '.log', BUILD_PROCESS_TIMEOUT_SECONDS);
			}
		}
		if (!$private) {
			copy($run . '/tools/DevTools.phar', $data . '/plugins/DevTools.phar');
			if (str_starts_with($mode, 'shared-composer-')) {
				smokeComposerVirions($data, $mode);
			} elseif ($mode === 'shared-folder') {
				smokeVirion($data . '/virions/SmokeLibrary', '1');
			} else {
				smokeVirion($run . '/inputs/virion-phar', '1');
				mkdir($data . '/virions', DIRECTORY_CREATE_PERMISSIONS, true);
				$phar = new Phar($data . '/virions/SmokeLibrary.phar');
				$phar->buildFromDirectory($run . '/inputs/virion-phar');
				$phar->setStub('<?php __HALT_COMPILER();');
				unset($phar);
			}
		}
		putenv('DEVTOOLS_SMOKE_MODE=' . ($private ? 'private' : 'shared'));
	smokeProcess([PHP_BINARY, $server . '/' . SERVER_ENTRYPOINT, '--no-wizard', '--disable-ansi', '--data=' . $data, '--plugins=' . $data . '/plugins'], $data, $data . '/console.log', SERVER_PROCESS_TIMEOUT_SECONDS);
		foreach (['SmokeA', 'SmokeB', 'SmokePlain'] as $name) {
			if (!is_file($data . '/' . $name . '.passed')) {
				throw new RuntimeException("{$mode}: {$name} did not complete. Inspect {$data}/console.log.");
			}
		}
		$passed[] = $mode;
		fwrite(STDOUT, "Passed {$mode}. Checked plain plugin loading, dependency order, resources, cross-plugin types and static state, and async virion calls.\n");
	}
	smokeWrite($run . '/result.json', json_encode(['axolotl' => $reference, 'php' => PHP_VERSION, 'passed' => $passed, 'clean_artifact_plugins' => ['SmokeA.phar', 'SmokeB.phar', 'SmokePlain.phar']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	fwrite(STDOUT, "Runtime evidence: {$run}\n");
} catch (Throwable $error) {
	fwrite(STDERR, "Runtime smoke failed: {$error->getMessage()}\n");
	exit(1);
}
