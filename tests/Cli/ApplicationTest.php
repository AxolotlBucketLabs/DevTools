<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Cli;

use NhanAZ\DevTools\Tests\TestCase;

final class ApplicationTest extends TestCase
{
    public function test_help_version_and_usage_are_machine_readable(): void
    {
        [$code, $result, $stderr] = $this->cli(['--help']);
        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertSame(1, $result['schema_version']);
        self::assertIsString($result['data']['help']);
        self::assertStringContainsString('prepare', $result['data']['help']);
        [$code, $version] = $this->cli(['--version']);
        self::assertSame(0, $code);
        self::assertSame($version['tool']['version'], $version['data']['version']);
        [$code, $invalid] = $this->cli(['build', '--unknown']);
        self::assertSame(2, $code);
        self::assertSame('cli.usage', $invalid['diagnostics'][0]['code']);
        self::assertSame('build', $invalid['command']);
    }

    public function test_version_does_not_leave_isolated_parser_directories(): void
    {
        $pattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devtools-parser-*';
        $before = glob($pattern) ?: [];

        [$code] = $this->cli(['--version']);

        self::assertSame(0, $code);
        self::assertSame($before, glob($pattern) ?: []);
    }

    public function test_build_cleans_isolated_parser_directory_when_the_process_exits(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'parser-cleanup');
        $this->copyFixture('SharedVirion', 'parser-cleanup/virions/SharedVirion');
        $pattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devtools-parser-*';
        $before = glob($pattern) ?: [];

        [$code, $result] = $this->cli(['build', '--project=' . $project]);

        self::assertSame(0, $code, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame($before, glob($pattern) ?: []);
    }

    public function test_doctor_preserves_structured_validation_failure(): void
    {
        [$code, $result] = $this->cli(['doctor', '--project=' . $this->temporaryDirectory]);
        self::assertSame(1, $code);
        self::assertFalse($result['success']);
        self::assertSame('plugin.manifest_missing', $result['diagnostics'][0]['code']);
        self::assertNotNull($result['diagnostics'][0]['file']);
        self::assertSame('failed', $result['checks']['structure']);
        self::assertSame('not_run', $result['checks']['runtime']);
    }

    public function test_build_inspect_extract_and_output_protection_work_from_another_directory(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'plugin with spaces');
        $this->copyFixture('SharedVirion', 'plugin with spaces/virions/SharedVirion');
        [$code, $built, $stderr] = $this->cli(['build', '--project=' . $project]);
        self::assertSame(0, $code, $stderr . json_encode($built, JSON_THROW_ON_ERROR));
        $artifact = $built['data']['artifact'];
        self::assertIsString($artifact);
        self::assertFileExists($project . '/build/BuildFixture.phar');
        self::assertSame(hash_file('sha256', $artifact), $built['data']['sha256']);
        self::assertSame('not_run', $built['checks']['runtime']);
        self::assertSame('passed', $built['checks']['artifact']);
        self::assertIsArray($built['data']['dependencies']);
        self::assertCount(1, $built['data']['dependencies']);
        [$code, $inspected] = $this->cli(['inspect', '--project=' . $project, '--artifact=build/BuildFixture.phar']);
        self::assertSame(0, $code);
        self::assertSame($built['data']['sha256'], $inspected['data']['sha256']);
        [$code, $extracted] = $this->cli(['extract', '--project=' . $project, '--artifact=build/BuildFixture.phar', '--out=unpacked']);
        self::assertSame(0, $code, json_encode($extracted, JSON_THROW_ON_ERROR));
        self::assertSame('passed', $extracted['checks']['artifact']);
        self::assertFileExists($project . '/unpacked/src/Main.php');
        [$code, $failed] = $this->cli(['build', '--project=' . $project]);
        self::assertSame(1, $code);
        self::assertSame('operation.build_failed', $failed['diagnostics'][0]['code']);
        self::assertSame($built['data']['sha256'], hash_file('sha256', $artifact));
        file_put_contents($project . '/src/Main.php', '<?php invalid syntax');
        [$code] = $this->cli(['build', '--project=' . $project, '--overwrite']);
        self::assertSame(1, $code);
        self::assertSame($built['data']['sha256'], hash_file('sha256', $artifact));
    }

    public function test_simple_plugin_requires_no_dependency_configuration(): void
    {
        $project = $this->copyFixture('BuildPlugin');
        unlink($project . '/devtools.yml');
        file_put_contents($project . '/src/Main.php', '<?php namespace Fixture\\Build; class Main extends \\pocketmine\\plugin\\PluginBase {}');
        [$code, $result] = $this->cli(['doctor', '--project=' . $project]);
        self::assertSame(0, $code, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame([], $result['data']['dependencies']);
        [$code, $result] = $this->cli(['build', '--project=' . $project]);
        self::assertSame(0, $code, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_options_do_not_create_outputs(): void
    {
        foreach ([['extract', '--out=output'], ['doctor', '--overwrite'], ['inspect', '--artifact=https://example.com/test.phar'], ['build', '--project=one', '--project=two'], ['build', '--type=auto']] as $arguments) {
            [$code, $result] = $this->cli($arguments);
            self::assertSame(2, $code, json_encode($result, JSON_THROW_ON_ERROR));
            self::assertSame('cli.usage', $result['diagnostics'][0]['code']);
        }
    }

    public function test_legacy_builder_preserves_working_directory_relative_paths(): void
    {
        $this->copyFixture('BuildPlugin', 'project');
        $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        [$code, $result] = $this->cli(['--project=project', '--virions=virions', '--out=output'], 'devtools-build.php');
        self::assertSame(0, $code, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertFileExists($this->temporaryDirectory . '/output/BuildFixture.phar');
    }

    public function test_cli_builds_a_virion_and_converts_a_plugin_to_zip(): void
    {
        $virion = $this->copyFixture('SharedVirion', 'virion');
        [$code, $built] = $this->cli(['build', '--type=virion', '--project=' . $virion]);
        self::assertSame(0, $code, json_encode($built, JSON_THROW_ON_ERROR));
        self::assertSame('virion', $built['data']['type']);
        self::assertFileExists($virion . '/build/SharedVirion.phar');

        $plugin = $this->copyFixture('BuildPlugin', 'plugin');
        $this->copyFixture('SharedVirion', 'plugin/virions/SharedVirion');
        [$code, $pluginBuild] = $this->cli(['build', '--project=' . $plugin]);
        self::assertSame(0, $code, json_encode($pluginBuild, JSON_THROW_ON_ERROR));
        self::assertIsString($pluginBuild['data']['artifact']);
        [$code, $zip] = $this->cli([
            'extract',
            '--artifact=' . $pluginBuild['data']['artifact'],
            '--out=' . $plugin . '/converted.zip',
            '--format=zip',
        ]);
        self::assertSame(0, $code, json_encode($zip, JSON_THROW_ON_ERROR));
        self::assertSame('zip', $zip['data']['format']);
        self::assertFileExists($plugin . '/converted.zip');
    }

    public function test_failed_virion_manifest_reports_structure_failure_in_json(): void
    {
        $virion = $this->copyFixture('InvalidVirion', 'invalid-virion');

        [$code, $result] = $this->cli(['build', '--type=virion', '--project=' . $virion]);

        self::assertSame(1, $code);
        self::assertFalse($result['success']);
        self::assertSame('failed', $result['checks']['structure']);
        self::assertSame('not_run', $result['checks']['artifact']);
    }

    public function test_cli_builds_dual_role_project_as_two_artifacts(): void
    {
        $project = $this->copyFixture('ValidPlugin', 'dual');
        $this->filesystem->ensureDirectory($project . '/src/Fixture/Library');
        file_put_contents(
            $project . '/src/Fixture/Library/Library.php',
            "<?php\nnamespace Fixture\\Library;\nfinal class Library {}\n",
        );
        file_put_contents(
            $project . '/virion.yml',
            "name: DualLibrary\nversion: 1.0.0\nantigen: Fixture\\Library\napi: 5.0.0\n",
        );
        [$code, $result] = $this->cli(['build', '--type=both', '--project=' . $project]);

        self::assertSame(0, $code, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertIsArray($result['data']['artifacts']);
        self::assertCount(2, $result['data']['artifacts']);
        self::assertFileExists($project . '/build/FixturePlugin-plugin.phar');
        self::assertFileExists($project . '/build/DualLibrary-virion.phar');
    }

    public function test_dual_build_reports_manifest_failure_as_structure_failure(): void
    {
        $project = $this->copyFixture('ValidPlugin', 'invalid-dual');
        file_put_contents($project . '/plugin.yml', "name: BrokenPlugin\nversion: 1.0.0\napi: 5.0.0\n");
        file_put_contents($project . '/virion.yml', "name: BrokenVirion\nversion: 1.0.0\nantigen: Broken\\Virion\napi: 5.0.0\n");

        [$code, $result] = $this->cli(['build', '--type=both', '--project=' . $project]);

        self::assertSame(1, $code);
        self::assertSame('failed', $result['checks']['structure']);
        self::assertSame('not_run', $result['checks']['dependencies']);
        self::assertSame('not_run', $result['checks']['artifact']);
    }

    public function test_dual_build_preserves_both_previous_artifacts_when_virion_build_fails(): void
    {
        $project = $this->copyFixture('ValidPlugin', 'dual-atomic');
        $this->filesystem->ensureDirectory($project . '/src/Fixture/Library');
        file_put_contents(
            $project . '/src/Fixture/Library/Library.php',
            "<?php\nnamespace Fixture\\Library;\nfinal class Library {}\n",
        );
        file_put_contents(
            $project . '/virion.yml',
            "name: DualLibrary\nversion: 1.0.0\nantigen: Fixture\\Library\napi: 5.0.0\n",
        );
        [$code] = $this->cli(['build', '--type=both', '--project=' . $project]);
        self::assertSame(0, $code);
        $pluginOutput = $project . '/build/FixturePlugin-plugin.phar';
        $virionOutput = $project . '/build/DualLibrary-virion.phar';
        $pluginHash = hash_file('sha256', $pluginOutput);
        $virionHash = hash_file('sha256', $virionOutput);

        file_put_contents(
            $project . '/src/Fixture/Library/Library.php',
            "<?php\nnamespace Fixture;\nfinal class Library {}\n",
        );
        [$code, $result] = $this->cli(['build', '--type=both', '--project=' . $project, '--overwrite']);

        self::assertSame(1, $code);
        self::assertFalse($result['success']);
        self::assertSame($pluginHash, hash_file('sha256', $pluginOutput));
        self::assertSame($virionHash, hash_file('sha256', $virionOutput));
    }

    public function test_bootstrap_failure_still_emits_json(): void
    {
        [$code, $result] = $this->cli(['--version'], 'devtools.php', ['-n', '-d', 'disable_functions=yaml_parse']);
        self::assertSame(1, $code);
        self::assertSame('cli.environment', $result['diagnostics'][0]['code']);
        self::assertSame('not_run', $result['checks']['runtime']);
    }

    /** @param list<string> $arguments
     * @param list<string> $phpOptions
     * @return array{int, array{schema_version: int, success: bool, command: string, data: array<mixed>, tool: array<mixed>, diagnostics: list<array<mixed>>, checks: array<mixed>}, string}
     */
    private function cli(array $arguments, string $entrypoint = 'devtools.php', array $phpOptions = []): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'phar.readonly=0', ...$phpOptions, dirname(__DIR__, 2) . '/bin/' . $entrypoint, ...$arguments, '--json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->temporaryDirectory);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        self::assertJson($stdout, "CLI exit {$exit}; stderr: {$stderr}");
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsInt($result['schema_version']);
        self::assertIsBool($result['success']);
        self::assertIsString($result['command']);
        self::assertIsArray($result['data']);
        self::assertIsArray($result['tool']);
        self::assertIsArray($result['checks']);
        self::assertIsArray($result['diagnostics']);
        $diagnostics = [];
        foreach ($result['diagnostics'] as $diagnostic) {
            self::assertIsArray($diagnostic);
            $diagnostics[] = $diagnostic;
        }

        return [$exit, ['schema_version' => $result['schema_version'], 'success' => $result['success'], 'command' => $result['command'], 'data' => $result['data'], 'tool' => $result['tool'], 'checks' => $result['checks'], 'diagnostics' => $diagnostics], $stderr];
    }
}
