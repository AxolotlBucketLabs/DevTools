<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Validation;

use PHPUnit\Framework\TestCase;

final class RepositoryMetadataTest extends TestCase
{
    private const VERSION = '2.1.0';
    private const COMPATIBILITY_BUILDER_VERSION = '2.1.0';
    private const PHP_ACTION_COMMIT = 'b8c3f9add4f2ad4a5e2624a1112aba899ee8db0e';

    public function test_release_version_is_synchronized(): void
    {
        $root = dirname(__DIR__, 2);
        $composerContents = file_get_contents($root . DIRECTORY_SEPARATOR . 'composer.json');
        self::assertIsString($composerContents);
        $composer = json_decode($composerContents, true, 512, JSON_THROW_ON_ERROR);
        $plugin = yaml_parse_file($root . DIRECTORY_SEPARATOR . 'plugin.yml');
        $changelog = file_get_contents($root . DIRECTORY_SEPARATOR . 'CHANGELOG.md');
        $releaseNotes = file_get_contents($root . DIRECTORY_SEPARATOR . 'RELEASE_NOTES.md');

        self::assertIsArray($composer);
        self::assertIsArray($plugin);
        $extra = $composer['extra'] ?? null;
        self::assertIsArray($extra);
        $devtools = $extra['devtools'] ?? null;
        self::assertIsArray($devtools);
        self::assertSame(self::VERSION, $devtools['release-version'] ?? null);
        self::assertSame(self::VERSION, $plugin['version'] ?? null);
        $require = $composer['require'] ?? null;
        self::assertIsArray($require);
        self::assertSame('^8.4', $require['php'] ?? null);
        $config = $composer['config'] ?? null;
        self::assertIsArray($config);
        $platform = $config['platform'] ?? null;
        self::assertIsArray($platform);
        self::assertSame('8.4.0', $platform['php'] ?? null);
        $requireDev = $composer['require-dev'] ?? null;
        self::assertIsArray($requireDev);
        self::assertSame('5.51.1', $requireDev['axolotl-pm/pocketmine-mp'] ?? null);
        self::assertArrayNotHasKey('pocketmine/pocketmine-mp', $requireDev);
        self::assertIsString($changelog);
        self::assertStringContainsString('## ' . self::VERSION . ' -', $changelog);
        self::assertIsString($releaseNotes);
        self::assertStringContainsString('# DevTools ' . self::VERSION, $releaseNotes);
    }

    public function test_workflows_are_valid_and_do_not_bypass_required_gates(): void
    {
        $root = dirname(__DIR__, 2);
        $ciPath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'ci.yml';
        $releasePath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'release.yml';
        $releasePluginPath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'release-plugin.yml';
        $virionPath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'build-virion.yml';
        $reusablePath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'build-plugin.yml';
        $candidatePath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'release-candidate.yml';
        $ci = file_get_contents($ciPath);
        $release = file_get_contents($releasePath);
        $releasePlugin = file_get_contents($releasePluginPath);
        $virion = file_get_contents($virionPath);
        $reusable = file_get_contents($reusablePath);
        $candidate = file_get_contents($candidatePath);

        self::assertIsArray(yaml_parse_file($ciPath));
        self::assertIsArray(yaml_parse_file($releasePath));
        self::assertIsArray(yaml_parse_file($releasePluginPath));
        self::assertIsArray(yaml_parse_file($virionPath));
        self::assertIsArray(yaml_parse_file($reusablePath));
        self::assertIsArray(yaml_parse_file($candidatePath));
        self::assertIsString($candidate);
        self::assertIsString($ci);
        self::assertIsString($release);
        self::assertIsString($releasePlugin);
        self::assertIsString($virion);
        self::assertIsString($reusable);
        self::assertStringContainsString('pull_request:', $ci);
        self::assertStringContainsString('branches: [main]', $ci);
        self::assertStringContainsString("php: ['8.4', '8.5']", $ci);
        self::assertStringContainsString('run: composer check', $ci);
        self::assertStringContainsString('contents: read', $ci);
        self::assertStringContainsString('workflow_dispatch:', $release);
        self::assertStringContainsString('candidate-run-id:', $release);
        self::assertStringContainsString('artifact-id:', $release);
        self::assertStringContainsString('candidate-commit:', $candidate);
        self::assertStringContainsString('ref: ${{ inputs.candidate-commit }}', $candidate);
        self::assertStringContainsString('contents: write', $release);
        self::assertStringContainsString('run: composer check', $candidate);
        self::assertStringContainsString('bin/validate-artifact.php', $candidate);
        self::assertStringNotContainsString('composer ', $release);
        self::assertStringNotContainsString('devtools.php', $release);
        self::assertStringContainsString('release-candidate.mjs verify', $release);
        self::assertStringContainsString('release-candidate.mjs download', $release);
        self::assertStringContainsString('--verify-tag', $release);
        self::assertStringContainsString('gh release create', $release);
        self::assertStringContainsString('workflow_call:', $reusable);
        self::assertStringContainsString('uses: ./.devtools-dependencies/tool', $reusable);
        self::assertStringContainsString('ref: ${{ steps.workflow-identity.outputs.sha }}', $reusable);
        self::assertStringContainsString('repository: ${{ steps.workflow-identity.outputs.repository }}', $reusable);
        self::assertStringContainsString("'.workflow_sha // empty'", $reusable);
        self::assertStringContainsString("'.workflow_repository // empty'", $reusable);
        self::assertSame(1, preg_match('/uses: actions\/upload-artifact@[a-f0-9]{40} # v[0-9]+\.[0-9]+\.[0-9]+/', $reusable));
        self::assertStringContainsString('artifact-id: ${{ steps.upload.outputs.artifact-id }}', $reusable);
        self::assertStringContainsString('contents: read', $reusable);
        self::assertStringNotContainsString('devtools-ref', $reusable);
        self::assertStringNotContainsString('retention-days', $reusable);
        self::assertStringNotContainsString('devtools-ref', $releasePlugin);
        self::assertStringNotContainsString('retention-days', $releasePlugin);
        self::assertStringNotContainsString('retention-days', $virion);
        self::assertStringNotContainsString('retention-days', $ci);
        self::assertStringNotContainsString('retention-days', $candidate);
        self::assertStringContainsString('Optional Axolotl-PM Git ref (release tag, branch, or full commit SHA)', $reusable);
        self::assertStringContainsString('repository: axolotl-pm/PocketMine-MP', $reusable);
        self::assertStringContainsString('Inspect built PHAR archive', $reusable);
        self::assertStringContainsString('Build pinned fixture with PHPStan max', $ci);
        self::assertStringNotContainsString('default: main', $reusable);

        foreach ([$ci, $candidate, $reusable] as $workflow) {
            self::assertStringContainsString('repository: axolotl-pm/setup-php-action', $workflow);
            self::assertStringContainsString('ref: ' . self::PHP_ACTION_COMMIT, $workflow);
            self::assertStringContainsString('setup-php-action/dist/index.js', $workflow);
            self::assertStringContainsString('Set up Axolotl-PM PHP on Node.js 24', $workflow);
            self::assertStringNotContainsString('pmmp/setup-php-action', $workflow);
        }

        foreach ([$ci, $candidate, $release] as $workflow) {
            self::assertStringNotContainsString('continue-on-error', $workflow);
            self::assertStringNotContainsString('|| true', $workflow);
            self::assertStringNotContainsString('pull_request_target', $workflow);
        }
    }

    public function test_server_workflow_baselines_match_the_locked_dependency(): void
    {
        $root = dirname(__DIR__, 2);
        $lockContents = file_get_contents($root . '/composer.lock');
        $ci = file_get_contents($root . '/.github/workflows/ci.yml');
        $monitor = file_get_contents($root . '/.github/workflows/axolotl-compatibility.yml');
        self::assertIsString($lockContents);
        self::assertIsString($ci);
        self::assertIsString($monitor);
        $lock = json_decode($lockContents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);
        $packages = $lock['packages-dev'] ?? null;
        self::assertIsArray($packages);
        foreach ($packages as $package) {
            self::assertIsArray($package);
            if (($package['name'] ?? null) !== 'axolotl-pm/pocketmine-mp') {
                continue;
            }
            $version = $package['version'] ?? null;
            $source = $package['source'] ?? null;
            self::assertIsString($version);
            self::assertIsArray($source);
            $reference = $source['reference'] ?? null;
            self::assertIsString($reference);
            self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $reference);
            self::assertStringContainsString('axolotl-ref: ' . $version, $ci);
            self::assertStringContainsString('BASELINE_TAG: "' . $version . '"', $monitor);
            self::assertStringContainsString('BASELINE_SHA: ' . $reference, $monitor);
            return;
        }
        self::fail('The server dependency must be present in composer.lock.');
    }

    public function test_compatibility_monitor_checks_the_pinned_builder_version(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/axolotl-compatibility.yml');
        self::assertIsString($workflow);
        self::assertIsArray(yaml_parse($workflow));

        self::assertSame(1, preg_match('/uses: NhanAZ\\/DevTools@[a-f0-9]{40} # v([0-9]+\\.[0-9]+\\.[0-9]+)/', $workflow, $pin));
        if (!isset($pin[1])) {
            self::fail('Compatibility monitor must declare its pinned builder version.');
        }
        self::assertSame(self::COMPATIBILITY_BUILDER_VERSION, $pin[1]);
        self::assertStringContainsString('project: examples/HelloShared', $workflow);
        self::assertStringContainsString('virions: examples', $workflow);
        self::assertStringContainsString('SharedGreeting', $workflow);
        self::assertStringNotContainsString('VisibleBorder', $workflow);
        self::assertStringNotContainsString('libRegRsp', $workflow);
        self::assertStringNotContainsString('NhanAZ-Plugins', $workflow);
        self::assertStringNotContainsString('NhanAZ-Libraries', $workflow);
    }

    public function test_composite_action_and_per_commit_example_are_valid(): void
    {
        $root = dirname(__DIR__, 2);
        $actionPath = $root . DIRECTORY_SEPARATOR . 'action.yml';
        $examplePath = $root . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . '.github'
            . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'build.yml';
        $ciPath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows'
            . DIRECTORY_SEPARATOR . 'ci.yml';
        $releasePath = $root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows'
            . DIRECTORY_SEPARATOR . 'release-candidate.yml';
        $action = yaml_parse_file($actionPath);
        $example = yaml_parse_file($examplePath);
        $actionContents = file_get_contents($actionPath);
        $exampleContents = file_get_contents($examplePath);
        $ciContents = file_get_contents($ciPath);
        $releaseContents = file_get_contents($releasePath);
        $agentGuide = file_get_contents($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ai-agent.md');
        $examplesReadme = file_get_contents($root . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'README.md');
        $agentTemplate = file_get_contents($root . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'agent'
            . DIRECTORY_SEPARATOR . 'AGENTS.md');

        self::assertIsArray($action);
        self::assertIsArray($example);
        $runs = $action['runs'] ?? null;
        $inputs = $action['inputs'] ?? null;
        $outputs = $action['outputs'] ?? null;
        self::assertIsArray($runs);
        self::assertIsArray($inputs);
        self::assertIsArray($outputs);
        $phpStanInput = $inputs['phpstan'] ?? null;
        $phpStanServerInput = $inputs['phpstan-server'] ?? null;
        $phpStanPathsInput = $inputs['phpstan-paths'] ?? null;
        self::assertIsArray($phpStanInput);
        self::assertIsArray($phpStanServerInput);
        self::assertIsArray($phpStanPathsInput);
        self::assertSame('off', $phpStanInput['default'] ?? null);
        self::assertSame('', $phpStanServerInput['default'] ?? null);
        self::assertSame('', $phpStanPathsInput['default'] ?? null);
        $pharOutput = $outputs['phar'] ?? null;
        $pluginNameOutput = $outputs['plugin-name'] ?? null;
        self::assertIsArray($pharOutput);
        self::assertIsArray($pluginNameOutput);
        self::assertSame('composite', $runs['using'] ?? null);
        self::assertSame('${{ steps.build.outputs.phar }}', $pharOutput['value'] ?? null);
        self::assertSame('${{ steps.build.outputs.plugin-name }}', $pluginNameOutput['value'] ?? null);
        self::assertIsString($actionContents);
        self::assertStringContainsString('bin/devtools.php" build --json', $actionContents);
        self::assertArrayHasKey('sha256', $outputs);
        self::assertArrayHasKey('metadata', $outputs);
        self::assertArrayHasKey('plugin-version', $outputs);
        self::assertStringContainsString('php -d phar.readonly=0', $actionContents);
        self::assertStringContainsString('INPUT_PROJECT: ${{ inputs.project }}', $actionContents);
        self::assertStringContainsString('Input overwrite must be true or false', $actionContents);
        self::assertStringContainsString('case "$INPUT_PHPSTAN" in', $actionContents);
        self::assertStringContainsString("off)\n", $actionContents);
        self::assertStringContainsString('phpstan-server is required when PHPStan is enabled', $actionContents);
        self::assertStringContainsString('SERVER_PATHS=()', $actionContents);
        self::assertStringContainsString('for SERVER_PATH in "${SERVER_PATHS[@]}"', $actionContents);
        self::assertStringContainsString('devtools-phpstan-$server_index.neon', $actionContents);
        self::assertStringContainsString('devtools-phpstan-cache-$server_index', $actionContents);
        self::assertStringContainsString('PHPSTAN_SCAN_ARGS', $actionContents);
        self::assertStringContainsString('PHPSTAN_WORKDIR="$RUNNER_TEMP/devtools-phpstan-work-$server_index"', $actionContents);
        self::assertStringContainsString('PHPSTAN_AUTOLOAD="$SERVER_PATH/vendor/autoload.php"', $actionContents);
        self::assertStringContainsString('--autoload-file="$PHPSTAN_AUTOLOAD"', $actionContents);
        self::assertStringContainsString('bin/devtools-phpstan-config.php', $actionContents);
        self::assertStringContainsString('vendor/phpstan/phpstan/phpstan.phar', $actionContents);
        self::assertStringContainsString('php "$PHPSTAN_PHAR" analyse', $actionContents);
        self::assertStringNotContainsString('vendor/phpstan/phpstan/phpstan" analyse', $actionContents);
        self::assertStringNotContainsString('vendor/bin/phpstan', $actionContents);
        self::assertIsString($ciContents);
        self::assertStringContainsString('--no-scripts', $actionContents);
        self::assertStringContainsString('--no-plugins', $actionContents);
        self::assertStringContainsString('--no-autoloader', $actionContents);
        self::assertStringContainsString('examples/ComposerPlugin', $ciContents);
        self::assertStringContainsString('prepare-dependencies: true', $ciContents);
        self::assertStringContainsString('virions: examples/ComposerPlugin/.devtools-virions', $ciContents);
        self::assertIsString($examplesReadme);
        self::assertStringContainsString('Composer-prepared virion example', $examplesReadme);
        self::assertIsString($exampleContents);
        self::assertStringContainsString("on:\n  push:\n  pull_request:\n  workflow_dispatch:", $exampleContents);
        self::assertStringContainsString('uses: NhanAZ/DevTools@v2', $exampleContents);
        self::assertSame(1, preg_match('/uses: actions\/upload-artifact@[a-f0-9]{40} # v[0-9]+\.[0-9]+\.[0-9]+/', $exampleContents));
        self::assertStringContainsString('path: ${{ steps.build.outputs.phar }}', $exampleContents);
        self::assertStringNotContainsString('retention-days', $exampleContents);
        self::assertStringNotContainsString('cancel-in-progress: true', $exampleContents);
        self::assertStringContainsString('repository: axolotl-pm/setup-php-action', $exampleContents);
        self::assertStringContainsString('ref: ' . self::PHP_ACTION_COMMIT, $exampleContents);
        self::assertStringContainsString('Set up Axolotl-PM PHP on Node.js 24', $exampleContents);
        self::assertStringContainsString('setup-php-action/dist/index.js', $exampleContents);
        self::assertStringNotContainsString('pmmp/setup-php-action', $exampleContents);
        self::assertStringNotContainsString('phpstan:', $exampleContents);
        self::assertStringContainsString('uses: ./', $ciContents);
        self::assertStringContainsString('name: HelloShared-${{ github.sha }}', $ciContents);
        self::assertStringContainsString('phpstan: "4"', $ciContents);
        self::assertStringContainsString("phpstan-server: |\n            tests/Fixtures/PhpStanServer\n            tests/Fixtures/PhpStanServer", $ciContents);
        self::assertStringContainsString('phpstan-paths: tests/Fixtures/PhpStanDependency/src', $ciContents);
        self::assertIsString($releaseContents);
        self::assertStringContainsString("grep -Fx '.github/workflows/build.yml'", $releaseContents);
        self::assertStringContainsString("grep -Fx 'ComposerPlugin/composer.json'", $releaseContents);
        self::assertStringContainsString("grep -Fx 'ComposerPlugin/composer.lock'", $releaseContents);
        self::assertStringContainsString("grep -Fx 'agent/AGENTS.md'", $releaseContents);
        self::assertIsString($agentGuide);
        self::assertStringContainsString('(cli.md)', $agentGuide);
        self::assertStringContainsString('(dependencies.md)', $agentGuide);
        self::assertStringContainsString('(github-actions.md)', $agentGuide);
        self::assertIsString($agentTemplate);
        self::assertStringContainsString('build-plugin.yml@v2` by default', $agentTemplate);
        self::assertStringContainsString('Do not guess virion repositories', $agentTemplate);
        self::assertStringContainsString('Upload exactly one artifact', $agentTemplate);
    }

    public function test_cli_documentation_matches_relative_extract_paths(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'cli.md');

        self::assertIsString($contents);
        self::assertStringContainsString('relative paths resolve from that project', $contents);
        self::assertStringContainsString('writes files directly under the resolved `--out` directory', $contents);
        self::assertStringNotContainsString('selects `plugins/` or `virions/` when the destination is relative to a project', $contents);
    }

    public function test_workflow_actions_are_immutable_and_checkouts_do_not_persist_tokens(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge(
            glob($root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . '*.yml') ?: [],
            [$root . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'build.yml'],
        );
        $checkoutCount = 0;
        $credentialOptOutCount = 0;
        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            $checkoutCount += preg_match_all('/uses:\s*actions\\/checkout@[a-f0-9]{40}(?:\s+# [^\r\n]+)?/', $contents);
            $credentialOptOutCount += preg_match_all('/persist-credentials:\s*false/', $contents);
            self::assertDoesNotMatchRegularExpression('/uses:\s*actions\\/(?:checkout|cache|upload-artifact|download-artifact)@v/', $contents);
        }

        self::assertGreaterThan(0, $checkoutCount);
        self::assertSame($checkoutCount, $credentialOptOutCount);
    }
}
