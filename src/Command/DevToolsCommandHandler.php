<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

use AxolotlBucketLabs\DevTools\Build\BuildException;

use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionStartupReporter;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use Throwable;
use function array_shift;
use function array_slice;
use function array_unshift;
use function count;
use function explode;
use function in_array;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

final class DevToolsCommandHandler
{
	private const OVERWRITE_OPTION = '--overwrite';
	private const FORMAT_OPTION_NAME = 'format';
	private const TYPE_OPTION_NAME = 'type';
	private const FORMAT_OPTION_PREFIX = '--' . self::FORMAT_OPTION_NAME . '=';
	private const TYPE_OPTION_PREFIX = '--' . self::TYPE_OPTION_NAME . '=';

	public function __construct(
		private readonly Plugin $plugin,
		private readonly FolderPluginDiscovery $pluginDiscovery,
		private readonly VirionRegistry $virions,
		private readonly string $pluginsDirectory,
		private readonly string $virionsDirectory,
		private readonly string $buildDirectory,
		private readonly BuildJobRunner $jobs,
		/** @var array<string, bool> */
		private readonly array $enabledCommands = [],
		private readonly ?DebugLogger $debug = null,
		private readonly ?Translator $translator = null,
	) {}

	/** @param list<string> $arguments */
	public function handle(CommandSender $sender, string $label, array $arguments) : bool
	{
		$label = strtolower(explode(':', $label, 2)[1] ?? $label);
		if ($label === 'makeplugin') {
			array_unshift($arguments, 'build');
		} elseif ($label === 'extractplugin') {
			array_unshift($arguments, 'extract');
		}

		$subcommand = strtolower(array_shift($arguments) ?? 'help');
		$this->debug?->debug("Received /devtools {$subcommand} command.");
		try {
			$this->validateArguments($subcommand, $arguments);
			if ($subcommand !== 'help'
				&& !$sender->hasPermission("devtools.command.{$subcommand}")
				&& !$sender->hasPermission('devtools.command')) {
				$sender->sendMessage($this->translate(TranslationKeys::COMMAND_PERMISSION, ['command' => $subcommand]));

				return true;
			}
			if (in_array($subcommand, ['doctor', 'build', 'extract'], true) && !($this->enabledCommands[$subcommand] ?? true)) {
				$sender->sendMessage($this->translate(TranslationKeys::COMMAND_DISABLED, ['command' => $subcommand]));

				return true;
			}
			return match ($subcommand) {
				'status' => $this->status($sender),
				'doctor' => $this->doctor($sender, $arguments),
				'build' => $this->build($sender, $arguments),
				'extract' => $this->extract($sender, $arguments),
				'virions' => $this->virions($sender),
				default => $this->help($sender),
			};
		} catch (Throwable $error) {
			$sender->sendMessage($this->translate(TranslationKeys::COMMAND_FAILED, ['error' => $error->getMessage()]));

			return true;
		}
	}

	/** @param list<string> $arguments */
	private function validateArguments(string $command, array $arguments) : void
	{
		if (in_array($command, ['build', 'extract'], true)) {
			if ($command === 'build' && isset($arguments[0]) && BuildTarget::tryFrom(strtolower($arguments[0])) !== null) {
				array_shift($arguments);
			}
			if ($arguments === [] || str_starts_with($arguments[0], '--')) {
				throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_SELECTION, ['command' => $command]));
			}
			$seen = [];
			foreach (array_slice($arguments, 1) as $argument) {
				if ($argument === self::OVERWRITE_OPTION) {
					if (isset($seen['overwrite'])) {
						throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_SELECTION, ['command' => $command]));
					}
					$seen['overwrite'] = true;
					continue;
				}
				if ($command === 'extract' && (str_starts_with($argument, self::FORMAT_OPTION_PREFIX) || str_starts_with($argument, self::TYPE_OPTION_PREFIX))) {
					$option = str_starts_with($argument, self::FORMAT_OPTION_PREFIX) ? self::FORMAT_OPTION_NAME : self::TYPE_OPTION_NAME;
					$prefix = $option === self::FORMAT_OPTION_NAME ? self::FORMAT_OPTION_PREFIX : self::TYPE_OPTION_PREFIX;
					$value = substr($argument, strlen($prefix));
					if (isset($seen[$option])) {
						throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_EXTRACT));
					}
					if ($option === self::FORMAT_OPTION_NAME && ExtractFormat::tryFrom($value) === null) {
						throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_EXTRACT_FORMAT));
					}
					if ($option === self::TYPE_OPTION_NAME && ExtractType::tryFrom($value) === null) {
						throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_EXTRACT_TYPE));
					}
					$seen[$option] = true;
					continue;
				}
				throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_SELECTION, ['command' => $command]));
			}
		} elseif ($command === 'doctor') {
			if (count($arguments) > 1 || (isset($arguments[0]) && str_starts_with($arguments[0], '--'))) {
				throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_DOCTOR));
			}
		} elseif (in_array($command, ['status', 'virions', 'help'], true) && $arguments !== []) {
			throw new BuildException($this->translate(TranslationKeys::COMMAND_USAGE_NO_ARGUMENTS, ['command' => $command]));
		}
	}

	private function status(CommandSender $sender) : bool
	{
		$projects = $this->pluginDiscovery->discover($this->pluginsDirectory);
		$loaded = $this->virions->loaded();
		$asyncKey = TranslationKeys::STATUS_ASYNC_NOT_NEEDED;
		if ($loaded !== []) {
			$asyncKey = $loaded[0]->asyncSupported ? TranslationKeys::STATUS_ASYNC_AVAILABLE : TranslationKeys::STATUS_ASYNC_UNAVAILABLE;
		}
		$sender->sendMessage($this->translate(TranslationKeys::STATUS_SUMMARY, [
			'version' => $this->plugin->getDescription()->getVersion(),
			'projects' => count($projects),
			'virions' => count($loaded),
			'async' => $this->translate($asyncKey),
			'job' => $this->translate($this->jobs->isBusy() ? TranslationKeys::STATUS_JOB_RUNNING : TranslationKeys::STATUS_JOB_IDLE),
			'output' => $this->buildDirectory,
		]));

		return true;
	}

	/** @param list<string> $arguments */
	private function doctor(CommandSender $sender, array $arguments) : bool
	{
		$selection = array_shift($arguments);
		return $this->submit(
			$sender,
			'doctor',
			['selection' => $selection],
			$selection === null ? TranslationKeys::QUEUE_DOCTOR_ALL : TranslationKeys::QUEUE_DOCTOR_PROJECT,
			$selection === null ? [] : ['project' => $selection],
			$selection === null ? 'all plugin folders' : "plugin {$selection}",
		);
	}

	/** @param list<string> $arguments */
	private function build(CommandSender $sender, array $arguments) : bool
	{
		$target = BuildTarget::PLUGIN->value;
		if (isset($arguments[0])) {
			$parsedTarget = BuildTarget::tryFrom(strtolower($arguments[0]));
			if ($parsedTarget !== null) {
				$target = $parsedTarget->value;
				array_shift($arguments);
			}
		}
		$selection = array_shift($arguments);
		if ($selection === null) {
			$sender->sendMessage($this->translate(TranslationKeys::COMMAND_BUILD_MISSING));

			return true;
		}
		$overwrite = in_array(self::OVERWRITE_OPTION, $arguments, true);
		$queueMessageKey = match (BuildTarget::from($target)) {
			BuildTarget::PLUGIN => TranslationKeys::QUEUE_BUILD_PLUGIN,
			BuildTarget::VIRION => TranslationKeys::QUEUE_BUILD_VIRION,
			BuildTarget::BOTH => TranslationKeys::QUEUE_BUILD_BOTH,
		};
		return $this->submit(
			$sender,
			'build',
			[
				'target' => $target,
				'selection' => $selection,
				'overwrite' => $overwrite,
			],
			$queueMessageKey,
			['project' => $selection],
			"{$target} build for {$selection}",
		);
	}

	/** @param list<string> $arguments */
	private function extract(CommandSender $sender, array $arguments) : bool
	{
		$selection = array_shift($arguments);
		if ($selection === null) {
			$sender->sendMessage($this->translate(TranslationKeys::COMMAND_EXTRACT_MISSING));

			return true;
		}
		$overwrite = in_array(self::OVERWRITE_OPTION, $arguments, true);
		$format = ExtractFormat::FOLDER->value;
		$type = ExtractType::AUTO->value;
		foreach ($arguments as $argument) {
			if (str_starts_with($argument, self::FORMAT_OPTION_PREFIX)) {
				$format = substr($argument, strlen(self::FORMAT_OPTION_PREFIX));
			} elseif (str_starts_with($argument, self::TYPE_OPTION_PREFIX)) {
				$type = substr($argument, strlen(self::TYPE_OPTION_PREFIX));
			}
		}
		return $this->submit(
			$sender,
			'extract',
			[
				'selection' => $selection,
				'format' => $format,
				'type' => $type,
				'overwrite' => $overwrite,
			],
			TranslationKeys::QUEUE_EXTRACT,
			['artifact' => $selection],
			"extract {$selection}",
		);
	}

	private function virions(CommandSender $sender) : bool
	{
		$entries = $this->virions->entries();
		if ($entries === []) {
			$sender->sendMessage($this->translate(TranslationKeys::VIRIONS_NONE, ['directory' => $this->virionsDirectory]));

			return true;
		}
		$reporter = new VirionStartupReporter($this->translator);
		foreach ($entries as $entry) {
			$message = $reporter->formatDiagnostic($entry);
			$sender->sendMessage($message);
		}

		return true;
	}

	private function help(CommandSender $sender) : bool
	{
		$sender->sendMessage($this->translate(TranslationKeys::COMMAND_HELP));

		return true;
	}

	/**
	 * @param array<string, mixed>      $payload
	 * @param array<string, int|string> $queueMessageParameters
	 */
	private function submit(CommandSender $sender, string $operation, array $payload, string $queueMessageKey, array $queueMessageParameters, string $description) : bool
	{
		if ($this->jobs->isBusy()) {
			$this->debug?->debug("Rejected {$operation} command because another background job is running.");
			$sender->sendMessage($this->translate(TranslationKeys::QUEUE_BUSY));

			return true;
		}
		$sender->sendMessage($this->translate($queueMessageKey, $queueMessageParameters));
		$this->debug?->debug("Submitting {$operation} command for {$description}.");
		$debug = $this->debug;
		if (!$this->jobs->submit($operation, $payload, static function (array $result) use ($sender, $debug) : void {
			foreach ($result['debug'] ?? [] as $message) {
				$debug?->debug($message);
			}
			if ($sender instanceof Player && !$sender->isConnected()) {
				return;
			}
			foreach ($result['messages'] as $message) {
				$sender->sendMessage($message);
			}
		})) {
			$this->debug?->debug("Failed to queue {$operation} command for {$description}.");
			$sender->sendMessage($this->translate(TranslationKeys::QUEUE_BUSY));
		}

		return true;
	}

	/** @param array<string, int|string> $parameters */
	private function translate(string $key, array $parameters = []) : string
	{
		return ($this->translator ?? Translator::fromResources())->translate($key, $parameters);
	}
}
