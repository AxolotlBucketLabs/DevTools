<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Command;

use AxolotlBucketLabs\DevTools\Command\BuildJobRunner;
use AxolotlBucketLabs\DevTools\Command\BuildWorkerApplication;
use AxolotlBucketLabs\DevTools\Command\DevToolsCommandHandler;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifest;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistryEntry;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use function file_put_contents;
use function hash_file;
use function implode;
use function strtolower;

final class DevToolsCommandHandlerTest extends TestCase
{
	public function test_virion_list_uses_localized_status_and_loading_summary() : void
	{
		$registry = new VirionRegistry();
		$manifest = new VirionManifest('ExampleVirion', '1.2.3', 'Example\\Library', [], [], [], 'virion.yml');
		$registry->record(new VirionRegistryEntry('source', $manifest, VirionStatus::LOADED, 'Loaded ExampleVirion 1.2.3 (Example\\Library).'));
		$registry->record(new VirionRegistryEntry('broken', $manifest, VirionStatus::FAILED, 'Technical validator detail'));

		$messages = $this->command(['virions'], registry: $registry, translator: Translator::fromResources('vie'));

		self::assertStringContainsString('Đã nạp ExampleVirion 1.2.3 (Example\\Library).', $messages);
		self::assertStringContainsString('Không thể nạp virion ExampleVirion. Technical validator detail', $messages);
		self::assertStringNotContainsString('Đã nạp ExampleVirion. Đã nạp', $messages);
		self::assertStringNotContainsString('Thất bại ExampleVirion. Không thể nạp', $messages);
		self::assertStringNotContainsString('Loaded ExampleVirion', $messages);
	}

	public function test_server_doctor_reports_missing_declared_dependency() : void
	{
		$this->copyFixture('BuildPlugin', 'plugins/Source');
		$messages = $this->command(['doctor', 'BuildFixture']);
		self::assertStringContainsString('Dependency check failed', $messages);
		self::assertStringContainsString('SharedVirion', $messages);
		self::assertStringNotContainsString('Project structure and dependency selection passed', $messages);
	}

	public function test_server_queue_acknowledgement_does_not_insert_english_operation_details() : void
	{
		$messages = $this->command(['doctor'], translator: Translator::fromResources('vie'));

		self::assertStringContainsString('Đã đưa việc kiểm tra tất cả thư mục plugin phát triển vào hàng đợi.', $messages);
		self::assertStringNotContainsString('all plugin folders', $messages);
	}

	public function test_server_queue_acknowledgement_keeps_localized_operation_and_project_context() : void
	{
		$cases = [
			[['doctor', 'QueueExample'], 'Đã đưa việc kiểm tra QueueExample vào hàng đợi.'],
			[['build', 'plugin', 'QueueExample'], 'Đã đưa việc build PHAR plugin cho QueueExample vào hàng đợi.'],
			[['build', 'virion', 'QueueExample'], 'Đã đưa việc build PHAR virion cho QueueExample vào hàng đợi.'],
			[['build', 'both', 'QueueExample'], 'Đã đưa việc build PHAR plugin và virion cho QueueExample vào hàng đợi.'],
			[['extract', 'QueueExample.phar'], 'Đã đưa việc giải nén QueueExample.phar vào hàng đợi.'],
		];
		$translator = Translator::fromResources('vie');

		foreach ($cases as [$arguments, $expected]) {
			$messages = $this->command($arguments, translator: $translator);

			self::assertStringContainsString($expected, $messages);
		}
	}

	public function test_async_result_is_not_sent_to_a_disconnected_player() : void
	{
		$runner = new class implements BuildJobRunner {
			/** @var (\Closure(array{success: bool, messages: list<string>, debug?: list<string>}): void)|null */
			private ?\Closure $completion = null;

			public function submit(string $operation, array $payload, callable $onComplete) : bool
			{
				$this->completion = \Closure::fromCallable($onComplete);

				return true;
			}

			public function isBusy() : bool
			{
				return false;
			}

			public function status() : string
			{
				return 'idle';
			}

			public function shutdown() : void {}

			public function complete() : void
			{
				$completion = $this->completion ?? throw new \LogicException('No background completion was queued.');
				$completion(['success' => true, 'messages' => ['Build completed.']]);
			}
		};
		$sender = new class extends Player {
			public int $sentMessages = 0;

			public function __construct() {}

			public function __destruct() {}

			public function hasPermission(\pocketmine\permission\Permission|string $name) : bool
			{
				return true;
			}

			public function isConnected() : bool
			{
				return false;
			}

			public function sendMessage(\pocketmine\lang\Translatable|string $message) : void
			{
				++$this->sentMessages;
			}
		};

		$this->command(['doctor'], runner: $runner, sender: $sender);
		$runner->complete();
		self::assertSame(1, $sender->sentMessages);
	}

	public function test_status_translates_worker_and_job_states() : void
	{
		$messages = $this->command(['status'], translator: Translator::fromResources('vie'));

		self::assertStringContainsString('chưa cần', $messages);
		self::assertStringContainsString('không có tác vụ đang chạy', $messages);
		self::assertStringNotContainsString('not needed yet', $messages);
		self::assertStringNotContainsString('idle', $messages);
	}

	public function test_status_translates_async_worker_availability() : void
	{
		$registry = new VirionRegistry();
		$manifest = new VirionManifest('ExampleVirion', '1.2.3', 'Example\\Library', [], [], [], 'virion.yml');
		$registry->record(new VirionRegistryEntry('source', $manifest, VirionStatus::LOADED, 'Loaded.', asyncSupported: true));

		$messages = $this->command(['status'], registry: $registry, translator: Translator::fromResources('vie'));

		self::assertStringContainsString('có sẵn', $messages);
		self::assertStringNotContainsString('available', $messages);
	}

	public function test_server_doctor_reports_invalid_build_declaration() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/Source');
		file_put_contents($project . '/devtools.yml', "unknown: true\n");
		$messages = $this->command(['doctor', 'BuildFixture']);
		self::assertStringContainsString('Unknown key', $messages);
		self::assertStringNotContainsString('Project structure and dependency selection passed', $messages);
	}

	public function test_server_doctor_describes_only_checks_it_performed() : void
	{
		$this->copyFixture('BuildPlugin', 'plugins/Source');
		$this->copyFixture('SharedVirion', 'virions/Shared');
		$messages = $this->command(['doctor', 'BuildFixture']);
		self::assertStringContainsString('Project structure and dependency selection are valid. Selected virion count: 1.', $messages);
		self::assertStringContainsString('runtime tests were not run', $messages);
		self::assertStringContainsString('/devtools virions', $messages);
	}

	public function test_build_and_legacy_alias_reject_typo_options_before_creating_output() : void
	{
		$this->copyFixture('BuildPlugin', 'plugins/Source');
		$this->copyFixture('SharedVirion', 'virions/Shared');
		foreach ([['devtools', ['build', 'BuildFixture', '--overwite']], ['makeplugin', ['BuildFixture', '--overwrite', '--overwrite']]] as [$label, $arguments]) {
			$messages = $this->command($arguments, $label);
			self::assertStringContainsString('Unknown or duplicate arguments', $messages);
			self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/build');
		}
	}

	public function test_extract_rejects_unknown_option_before_writing_files() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/Source');
		$this->copyFixture('SharedVirion', 'virions/Shared');
		$result = $this->builder()->build($project, $this->temporaryDirectory . '/virions', $this->temporaryDirectory . '/build');
		$messages = $this->command([$result->outputPath, '--overwite'], 'extractplugin');
		self::assertStringContainsString('Unknown or duplicate arguments', $messages);
		self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/plugins/BuildFixture');
	}

	public function test_qualified_legacy_aliases_build_and_extract() : void
	{
		$this->copyFixture('BuildPlugin', 'plugins/Source');
		$this->copyFixture('SharedVirion', 'virions/Shared');
		$messages = $this->command(['BuildFixture'], 'devtools:makeplugin');
		self::assertStringContainsString('Built BuildFixture successfully', $messages);
		self::assertFileExists($this->temporaryDirectory . '/build/BuildFixture.phar');

		$messages = $this->command(['BuildFixture'], 'devtools:extractplugin');
		self::assertStringContainsString('Files extracted to', $messages);
		self::assertFileExists($this->temporaryDirectory . '/plugins/BuildFixture/plugin.yml');
	}

	public function test_server_builds_virions_and_archives_plugin_phars() : void
	{
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$messages = $this->command(['build', 'virion', 'SharedVirion']);
		self::assertStringContainsString('Built virion SharedVirion successfully', $messages);
		self::assertFileExists($this->temporaryDirectory . '/build/SharedVirion.phar');

		$this->copyFixture('BuildPlugin', 'plugins/Source');
		$messages = $this->command(['build', 'BuildFixture']);
		self::assertStringContainsString('Built BuildFixture successfully', $messages);
		$messages = $this->command(['extract', 'BuildFixture.phar', '--format=zip']);
		self::assertStringContainsString('Archive created at', $messages);
		self::assertFileExists($this->temporaryDirectory . '/build/BuildFixture.zip');
	}

	public function test_server_dual_build_rejects_a_plugin_only_project_before_writing_output() : void
	{
		$this->copyFixture('ValidPlugin', 'plugins/Source');

		$messages = $this->command(['build', 'both', 'FixturePlugin']);

		self::assertStringContainsString('contain both plugin.yml and virion.yml', $messages);
		self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/build');
	}

	public function test_server_dual_build_preserves_both_previous_artifacts_when_virion_build_fails() : void
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

	public function test_server_extract_rejects_a_requested_role_that_does_not_match_the_artifact() : void
	{
		$project = $this->copyFixture('BuildPlugin', 'plugins/Source');
		$this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$messages = $this->command(['build', 'BuildFixture']);
		self::assertStringContainsString('Built BuildFixture successfully', $messages);

		$messages = $this->command(['extract', 'BuildFixture.phar', '--type=virion']);

		self::assertStringContainsString('does not match requested type virion', $messages);
		self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/virions/BuildFixture');
	}

	public function test_server_build_can_be_disabled_without_disabling_folder_loading_commands() : void
	{
		$messages = $this->command(['build', 'BuildFixture'], 'devtools', ['build' => false]);

		self::assertStringContainsString('disabled by configuration', $messages);
		self::assertStringNotContainsString('Queued DevTools', $messages);
	}

	/**
	 * @param list<string>        $arguments
	 * @param array<string, bool> $enabledCommands
	 */
	private function command(
		array $arguments,
		string $label = 'devtools',
		array $enabledCommands = [],
		?VirionRegistry $registry = null,
		?Translator $translator = null,
		?BuildJobRunner $runner = null,
		?CommandSender $sender = null,
	) : string
	{
		[$reader, $validator] = $this->validationServices();
		$discovery = new FolderPluginDiscovery();
		$yaml = new YamlReader();
		$virionManifestReader = new VirionManifestReader($yaml);
		$virionFactory = new VirionProjectFactory($virionManifestReader);
		$virionDiscovery = new VirionDiscovery($this->filesystem);
		$runner ??= new class (
			$this->temporaryDirectory . '/plugins',
			$this->temporaryDirectory . '/virions',
			$this->temporaryDirectory . '/build',
		) implements BuildJobRunner {
			public function __construct(
				private readonly string $pluginsDirectory,
				private readonly string $virionsDirectory,
				private readonly string $buildDirectory,
			) {}

			public function submit(string $operation, array $payload, callable $onComplete) : bool
			{
				$onComplete(BuildWorkerApplication::run($operation, $payload + [
					'pluginsDirectory' => $this->pluginsDirectory,
					'virionsDirectory' => $this->virionsDirectory,
					'buildDirectory' => $this->buildDirectory,
				]));

				return true;
			}

			public function isBusy() : bool
			{
				return false;
			}

			public function status() : string
			{
				return 'idle';
			}

			public function shutdown() : void {}
		};
		$handler = new DevToolsCommandHandler(
			$this->createMock(Plugin::class),
			$discovery,
			$registry ?? new VirionRegistry(),
			$this->temporaryDirectory . '/plugins',
			$this->temporaryDirectory . '/virions',
			$this->temporaryDirectory . '/build',
			$runner,
			$enabledCommands,
			translator: $translator,
		);
		$messages = [];
		if ($sender === null) {
			$sender = $this->createMock(CommandSender::class);
			$sender->method('hasPermission')->willReturn(true);
			$sender->method('sendMessage')->willReturnCallback(static function (string $message) use (&$messages) : void {
				$messages[] = $message;
			});
		}
		$handler->handle($sender, $label, $arguments);
		return implode("\n", $messages);
	}
}
