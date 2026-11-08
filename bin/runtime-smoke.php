<?php

declare(strict_types=1);

/** @param list<string> $command */
function smokeProcess(array $command, string $directory, string $log, int $timeout): void
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.err', 'w']], $pipes, $directory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start smoke-test process.');
    }
    $deadline = time() + $timeout;
    do {
        usleep(100000);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
    } while (time() < $deadline);
    if ($status['running']) {
        fwrite($pipes[0], "stop\n");
        usleep(500000);
        proc_terminate($process);
    }
    fclose($pipes[0]);
    proc_close($process);
    if ($status['running'] || $status['exitcode'] !== 0) {
        throw new RuntimeException("Process failed or exceeded {$timeout}s. See {$log} and {$log}.err.");
    }
}

function smokeWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true)) {
        throw new RuntimeException("Cannot create directory for {$path}.");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Cannot write {$path}.");
    }
}

function smokeRead(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }

    return $contents;
}

function smokePlugin(string $root, string $name, string $other, string $version): void
{
    $fixture = dirname(__DIR__) . '/tests/Fixtures/RuntimeSmoke';
    smokeWrite($root . '/plugin.yml', "name: {$name}\nversion: 1.0.0\nmain: RuntimeSmoke\\{$name}\\Main\napi: 5.0.0\nsrc-namespace-prefix: RuntimeSmoke\\{$name}\n");
    if ($name === 'SmokeA') {
        file_put_contents($root . '/plugin.yml', "depend: [SmokeB]\n", FILE_APPEND);
    }
    smokeWrite($root . '/devtools.yml', "virions:\n  - name: SmokeLibrary\n    version: ^{$version}.0.0\n");
    smokeWrite($root . '/resources/probe.txt', 'resource-ok');
    foreach (['Main', 'AsyncProbe'] as $class) {
        smokeWrite($root . '/src/' . $class . '.php', strtr(smokeRead($fixture . '/' . $class . '.php.tpl'), ['{{PLUGIN}}' => $name, '{{OTHER}}' => $other, '{{VERSION}}' => $version]));
    }
}

function smokeVirion(string $root, string $version): void
{
    smokeWrite($root . '/virion.yml', "name: SmokeLibrary\nversion: {$version}.0.0\nantigen: RuntimeSmoke\\Library\napi: 5.0.0\n");
    smokeWrite($root . '/src/Value.php', strtr(smokeRead(dirname(__DIR__) . '/tests/Fixtures/RuntimeSmoke/Value.php.tpl'), ['{{VERSION}}' => $version]));
}

function smokeComposerPlugin(string $root, bool $transitive): void
{
    unlink($root . '/devtools.yml');
    $composer = ['name' => 'smoke/plugin', 'require' => ['smoke/library' => '^1.0']];
    smokeWrite($root . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    ksort($composer);
    smokeWrite($root . '/composer.lock', json_encode([
        'content-hash' => md5(json_encode($composer, JSON_THROW_ON_ERROR)),
        'packages' => array_values(smokeComposerPackages($transitive)),
    ], JSON_THROW_ON_ERROR));
}

/** @return array<string, array<string, mixed>> */
function smokeComposerPackages(bool $transitive): array
{
    $packages = ['smoke/library' => [
        'name' => 'smoke/library', 'version' => '1.0.0',
        'autoload' => ['psr-4' => ['RuntimeSmoke\\Library\\' => 'src/']],
        'require' => $transitive ? ['smoke/helper' => '^1.0'] : [],
        'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'RuntimeSmoke\\Library']],
    ]];
    if ($transitive) {
        $packages['smoke/helper'] = [
            'name' => 'smoke/helper', 'version' => '1.0.0',
            'autoload' => ['psr-4' => ['RuntimeSmoke\\Helper\\' => 'src/']],
            'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'RuntimeSmoke\\Helper']],
        ];
    }
    return $packages;
}

function smokeComposerVirions(string $data, string $mode): void
{
    $mixed = $mode !== 'shared-composer-folder';
    $library = $data . '/virions/SmokeLibrary';
    smokeVirion($library, '1');
    $packages = smokeComposerPackages($mixed);
    smokeWrite($library . '/composer.json', json_encode($packages['smoke/library'], JSON_THROW_ON_ERROR));
    if (!$mixed) {
        return;
    }
    smokeWrite($library . '/virion.yml', smokeRead($library . '/virion.yml') . "virions: [{name: smoke.helper, version: '1.0.0'}]\n");
    smokeWrite($library . '/src/Value.php', str_replace("return '1';", 'return \\RuntimeSmoke\\Helper\\Value::version();', smokeRead($library . '/src/Value.php')));
    $helper = $data . '/virions/SmokeHelper';
    smokeWrite($helper . '/virion.yml', "name: SmokeHelper\nversion: 1.0.0\nantigen: RuntimeSmoke\\Helper\napi: 5.0.0\n");
    smokeWrite($helper . '/src/Value.php', "<?php namespace RuntimeSmoke\\Helper; final class Value { public static function version(): string { return '1'; } }\n");
    smokeWrite($helper . '/composer.json', json_encode($packages['smoke/helper'], JSON_THROW_ON_ERROR));
    $project = $data . '/plugins/SmokeA';
    $filesystem = new AxolotlBucketLabs\DevTools\Support\Filesystem();
    foreach (['smoke/library' => $library, 'smoke/helper' => $helper] as $name => $source) {
        $filesystem->copyTree($source, $project . '/vendor/' . $name);
    }
    smokeWrite($project . '/vendor/composer/installed.json', json_encode(['packages' => array_values($packages)], JSON_THROW_ON_ERROR));
    (new AxolotlBucketLabs\DevTools\Build\ComposerVirionPreparer($filesystem))->prepare($project, $data . '/prepared');
    $preparedName = $mode === 'shared-composer-mixed' ? 'smoke.library' : 'smoke.helper';
    $source = $mode === 'shared-composer-mixed' ? $library : $helper;
    if ($mode === 'shared-composer-local-parent') {
        smokeWrite($library . '/virion.yml', str_replace('name: smoke.helper', 'name: SmokeHelper', smokeRead($library . '/virion.yml')));
    } else {
        $filesystem->removeTree($source);
    }
    $filesystem->copyTree($data . '/prepared/' . $preparedName, $data . '/virions/' . $preparedName);
}

try {
    $root = dirname(__DIR__);
    $server = null;
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (str_starts_with($argument, '--server=')) {
            $server = realpath(substr($argument, 9));
        } elseif ($argument === '--help') {
            fwrite(STDOUT, "Usage: php -d phar.readonly=0 bin/runtime-smoke.php --server=/path/to/prepared/axolotl\nCreates fresh data directories under build/runtime-smoke-*. Requires the exact Axolotl-PM source from composer.lock and its installed dependencies. No downloads are performed.\n");
            exit(0);
        } else {
            throw new RuntimeException("Unknown argument: {$argument}");
        }
    }
    if (!is_string($server) || !is_file($server . '/vendor/autoload.php')) {
        throw new RuntimeException('Pass --server=<prepared Axolotl-PM source directory> with its own installed vendor dependencies.');
    }
    if (!extension_loaded('pmmpthread') || ini_get('phar.readonly') !== '0') {
        throw new RuntimeException('Use the Axolotl-PM-compatible PHP binary with pmmpthread and -d phar.readonly=0.');
    }
    require $root . '/vendor/autoload.php';
    $reference = Composer\InstalledVersions::getReference('axolotl-pm/pocketmine-mp');
    $installed = Composer\InstalledVersions::getInstallPath('axolotl-pm/pocketmine-mp');
    if ($reference === null || $installed === null) {
        throw new RuntimeException('Install DevTools development dependencies from composer.lock first.');
    }
    $lock = json_decode(smokeRead($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
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
    if (!is_string($expectedReference) || preg_match('/^[a-f0-9]{40}$/', $expectedReference) !== 1) {
        throw new RuntimeException('DevTools composer.lock has no valid Axolotl-PM source commit.');
    }
    if ($reference !== $expectedReference) {
        throw new RuntimeException('Installed Axolotl-PM source differs from DevTools composer.lock.');
    }
    foreach (['src', 'generated', 'resources'] as $directory) {
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
            if (hash_file('sha256', $entry->getPathname()) !== hash_file('sha256', $serverFile)) {
                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if (!in_array($extension, ['md', 'json', 'yml'], true) || str_replace("\r\n", "\n", smokeRead($entry->getPathname())) !== str_replace("\r\n", "\n", smokeRead($serverFile))) {
                    throw new RuntimeException("Server differs from locked Axolotl-PM {$reference}: {$relative}");
                }
            }
        }
    }
    if (str_replace("\r\n", "\n", smokeRead($server . '/composer.lock')) !== str_replace("\r\n", "\n", smokeRead($installed . '/composer.lock'))) {
        throw new RuntimeException('Prepared server composer.lock differs from the locked Axolotl-PM source.');
    }
    $run = $root . '/build/runtime-smoke-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    smokeWrite($run . '/target.json', json_encode(['axolotl' => $reference, 'php' => PHP_VERSION, 'pmmpthread' => phpversion('pmmpthread')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    smokeProcess([PHP_BINARY, '-d', 'phar.readonly=0', $root . '/bin/devtools-build.php', '--project=' . $root, '--out=' . $run . '/tools'], $root, $run . '/build-devtools.log', 60);
    $passed = [];
    foreach (['shared-folder', 'shared-phar', 'shared-composer-folder', 'shared-composer-mixed', 'shared-composer-local-parent', 'private-phar'] as $mode) {
        $data = $run . '/' . $mode;
        smokeWrite($data . '/server.properties', "language=eng\nserver-ip=127.0.0.1\nserver-port=0\nenable-ipv6=off\nenable-query=off\nxbox-auth=off\nlevel-type=FLAT\nview-distance=2\nmax-players=1\n");
        smokeWrite($data . '/pocketmine.yml', "settings:\n  async-workers: 2\n  enable-dev-builds: true\n  send-usage: false\nauto-report:\n  enabled: false\nauto-updater:\n  enabled: false\nnetwork:\n  upnp-forwarding: false\ntransport:\n  name: raknet\n");
        $private = $mode === 'private-phar';
        $plain = $private ? $run . '/inputs/SmokePlain' : $data . '/plugins/SmokePlain';
        smokeWrite($plain . '/plugin.yml', "name: SmokePlain\nversion: 1.0.0\nmain: RuntimeSmoke\\Plain\\Main\napi: 5.0.0\nsrc-namespace-prefix: RuntimeSmoke\\Plain\n");
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
            smokeProcess([PHP_BINARY, '-d', 'phar.readonly=0', $root . '/bin/devtools-build.php', '--project=' . $plain, '--out=' . $data . '/plugins'], $root, $data . '/build-SmokePlain.log', 60);
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
                smokeProcess([PHP_BINARY, '-d', 'phar.readonly=0', $root . '/bin/devtools-build.php', '--project=' . $project, '--virions=' . $virions, '--out=' . $data . '/plugins'], $root, $data . '/build-' . $name . '.log', 60);
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
                mkdir($data . '/virions', 0777, true);
                $phar = new Phar($data . '/virions/SmokeLibrary.phar');
                $phar->buildFromDirectory($run . '/inputs/virion-phar');
                $phar->setStub('<?php __HALT_COMPILER();');
                unset($phar);
            }
        }
        putenv('DEVTOOLS_SMOKE_MODE=' . ($private ? 'private' : 'shared'));
        smokeProcess([PHP_BINARY, $server . '/src/PocketMine.php', '--no-wizard', '--disable-ansi', '--data=' . $data, '--plugins=' . $data . '/plugins'], $data, $data . '/console.log', 90);
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
