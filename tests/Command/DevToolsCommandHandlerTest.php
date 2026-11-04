<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Command;

use NhanAZ\DevTools\Command\BuildJobRunner;
use NhanAZ\DevTools\Command\BuildWorkerApplication;
use NhanAZ\DevTools\Command\DevToolsCommandHandler;
use NhanAZ\DevTools\Loader\FolderPluginDiscovery;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Tests\TestCase;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionRegistry;
use pocketmine\command\CommandSender;
use pocketmine\plugin\Plugin;

final class DevToolsCommandHandlerTest extends TestCase
{
    public function test_server_doctor_reports_missing_declared_dependency(): void
    {
        $this->copyFixture('BuildPlugin', 'plugins/Source');
        $messages = $this->command(['doctor', 'BuildFixture']);
        self::assertStringContainsString('Dependency check failed', $messages);
        self::assertStringContainsString('SharedVirion', $messages);
        self::assertStringNotContainsString('Project structure and dependency selection passed', $messages);
    }

    public function test_server_doctor_reports_invalid_build_declaration(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'plugins/Source');
        file_put_contents($project . '/devtools.yml', "unknown: true\n");
        $messages = $this->command(['doctor', 'BuildFixture']);
        self::assertStringContainsString('Unknown key', $messages);
        self::assertStringNotContainsString('Project structure and dependency selection passed', $messages);
    }

    public function test_server_doctor_describes_only_checks_it_performed(): void
    {
        $this->copyFixture('BuildPlugin', 'plugins/Source');
        $this->copyFixture('SharedVirion', 'virions/Shared');
        $messages = $this->command(['doctor', 'BuildFixture']);
        self::assertStringContainsString('Project structure and dependency selection passed (1 virions)', $messages);
        self::assertStringContainsString('runtime tests were not run', $messages);
        self::assertStringContainsString('/devtools virions', $messages);
    }

    public function test_build_and_legacy_alias_reject_typo_options_before_creating_output(): void
    {
        $this->copyFixture('BuildPlugin', 'plugins/Source');
        $this->copyFixture('SharedVirion', 'virions/Shared');
        foreach ([['devtools', ['build', 'BuildFixture', '--overwite']], ['makeplugin', ['BuildFixture', '--overwrite', '--overwrite']]] as [$label, $arguments]) {
            $messages = $this->command($arguments, $label);
            self::assertStringContainsString('Unknown or duplicate arguments', $messages);
            self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/build');
        }
    }

    public function test_extract_rejects_unknown_option_before_writing_files(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'plugins/Source');
        $this->copyFixture('SharedVirion', 'virions/Shared');
        $result = $this->builder()->build($project, $this->temporaryDirectory . '/virions', $this->temporaryDirectory . '/build');
        $messages = $this->command([$result->outputPath, '--overwite'], 'extractplugin');
        self::assertStringContainsString('Unknown or duplicate arguments', $messages);
        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/plugins/BuildFixture');
    }

    public function test_qualified_legacy_aliases_build_and_extract(): void
    {
        $this->copyFixture('BuildPlugin', 'plugins/Source');
        $this->copyFixture('SharedVirion', 'virions/Shared');
        $messages = $this->command(['BuildFixture'], 'devtools:makeplugin');
        self::assertStringContainsString('Built BuildFixture successfully', $messages);
        self::assertFileExists($this->temporaryDirectory . '/build/BuildFixture.phar');

        $messages = $this->command(['BuildFixture'], 'devtools:extractplugin');
        self::assertStringContainsString('Extracted', $messages);
        self::assertFileExists($this->temporaryDirectory . '/plugins/BuildFixture/plugin.yml');
    }

    public function test_server_builds_virions_and_archives_plugin_phars(): void
    {
        $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $messages = $this->command(['build', 'virion', 'SharedVirion']);
        self::assertStringContainsString('Built virion SharedVirion successfully', $messages);
        self::assertFileExists($this->temporaryDirectory . '/build/SharedVirion.phar');

        $this->copyFixture('BuildPlugin', 'plugins/Source');
        $messages = $this->command(['build', 'BuildFixture']);
        self::assertStringContainsString('Built BuildFixture successfully', $messages);
        $messages = $this->command(['extract', 'BuildFixture.phar', '--format=zip']);
        self::assertStringContainsString('Archived', $messages);
        self::assertFileExists($this->temporaryDirectory . '/build/BuildFixture.zip');
    }

    public function test_server_dual_build_preserves_both_previous_artifacts_when_virion_build_fails(): void
    {
        $project = $this->copyFixture('ValidPlugin', 'plugins/Dual');
        $this->filesystem->ensureDirectory($project . '/src/Fixture/Library');
        file_put_contents(
            $project . '/src/Fixture/Library/Library.php',
            "<?php\nnamespace Fixture\\Library;\nfinal class Library {}\n",
        );
        file_put_contents(
            $project . '/virion.yml',
            "name: DualLibrary\nversion: 1.0.0\nantigen: Fixture\\Library\napi: 5.0.0\n",
        );

        $messages = $this->command(['build', 'both', 'FixturePlugin']);
        self::assertStringContainsString('Built dual-role project successfully', $messages);
        $pluginOutput = $this->temporaryDirectory . '/build/FixturePlugin-plugin.phar';
        $virionOutput = $this->temporaryDirectory . '/build/DualLibrary-virion.phar';
        $pluginHash = hash_file('sha256', $pluginOutput);
        $virionHash = hash_file('sha256', $virionOutput);

        file_put_contents(
            $project . '/src/Fixture/Library/Library.php',
            "<?php\nnamespace Fixture;\nfinal class Library {}\n",
        );
        $messages = $this->command(['build', 'both', 'FixturePlugin', '--overwrite']);

        self::assertStringContainsString('could not complete', strtolower($messages));
        self::assertSame($pluginHash, hash_file('sha256', $pluginOutput));
        self::assertSame($virionHash, hash_file('sha256', $virionOutput));
    }

    public function test_server_extract_rejects_a_requested_role_that_does_not_match_the_artifact(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'plugins/Source');
        $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $messages = $this->command(['build', 'BuildFixture']);
        self::assertStringContainsString('Built BuildFixture successfully', $messages);

        $messages = $this->command(['extract', 'BuildFixture.phar', '--type=virion']);

        self::assertStringContainsString('does not match requested type virion', $messages);
        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/virions/BuildFixture');
    }

    public function test_server_build_can_be_disabled_without_disabling_folder_loading_commands(): void
    {
        $messages = $this->command(['build', 'BuildFixture'], 'devtools', ['build' => false]);

        self::assertStringContainsString('disabled by configuration', $messages);
        self::assertStringNotContainsString('Queued DevTools', $messages);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, bool> $enabledCommands
     */
    private function command(array $arguments, string $label = 'devtools', array $enabledCommands = []): string
    {
        [$reader, $validator] = $this->validationServices();
        $discovery = new FolderPluginDiscovery();
        $yaml = new YamlReader();
        $virionManifestReader = new VirionManifestReader($yaml);
        $virionFactory = new VirionProjectFactory($virionManifestReader);
        $virionDiscovery = new VirionDiscovery($this->filesystem);
        $handler = new DevToolsCommandHandler(
            $this->createMock(Plugin::class),
            $discovery,
            new VirionRegistry(),
            $this->temporaryDirectory . '/plugins',
            $this->temporaryDirectory . '/virions',
            $this->temporaryDirectory . '/build',
            new class (
                $this->temporaryDirectory . '/plugins',
                $this->temporaryDirectory . '/virions',
                $this->temporaryDirectory . '/build',
            ) implements BuildJobRunner {
                public function __construct(
                    private readonly string $pluginsDirectory,
                    private readonly string $virionsDirectory,
                    private readonly string $buildDirectory,
                ) {}

                public function submit(string $operation, array $payload, callable $onComplete): bool
                {
                    $onComplete(BuildWorkerApplication::run($operation, $payload + [
                        'pluginsDirectory' => $this->pluginsDirectory,
                        'virionsDirectory' => $this->virionsDirectory,
                        'buildDirectory' => $this->buildDirectory,
                    ]));

                    return true;
                }

                public function isBusy(): bool
                {
                    return false;
                }

                public function status(): string
                {
                    return 'idle';
                }

                public function shutdown(): void {}
            },
            $enabledCommands,
        );
        $messages = [];
        $sender = $this->createMock(CommandSender::class);
        $sender->method('hasPermission')->willReturn(true);
        $sender->method('sendMessage')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $handler->handle($sender, $label, $arguments);
        return implode("\n", $messages);
    }
}
