<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use pocketmine\command\CommandSender;
use pocketmine\plugin\Plugin;
use Throwable;
use function array_shift;
use function array_slice;
use function array_unshift;
use function basename;
use function count;
use function explode;
use function in_array;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function substr;

final class DevToolsCommandHandler
{
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
				$sender->sendMessage($this->translate('command.permission', ['command' => $subcommand]));

				return true;
			}
			if (in_array($subcommand, ['doctor', 'build', 'extract'], true) && !($this->enabledCommands[$subcommand] ?? true)) {
				$sender->sendMessage($this->translate('command.disabled', ['command' => $subcommand]));

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
			$sender->sendMessage($this->translate('command.failed', ['error' => $error->getMessage()]));

			return true;
		}
	}

	/** @param list<string> $arguments */
	private function validateArguments(string $command, array $arguments) : void
	{
		if (in_array($command, ['build', 'extract'], true)) {
			if ($command === 'build' && isset($arguments[0]) && in_array(strtolower($arguments[0]), ['plugin', 'virion', 'both'], true)) {
				array_shift($arguments);
			}
			if ($arguments === [] || str_starts_with($arguments[0], '--')) {
				throw new BuildException($this->translate('command.usage.selection', ['command' => $command]));
			}
			$seen = [];
			foreach (array_slice($arguments, 1) as $argument) {
				if ($argument === '--overwrite') {
					if (isset($seen['overwrite'])) {
						throw new BuildException($this->translate('command.usage.selection', ['command' => $command]));
					}
					$seen['overwrite'] = true;
					continue;
				}
				if ($command === 'extract' && preg_match('/^--(format|type)=(folder|zip|auto|plugin|virion|both)$/D', $argument, $match) === 1) {
					if (isset($seen[$match[1]])) {
						throw new BuildException($this->translate('command.usage.extract'));
					}
					if ($match[1] === 'format' && !in_array($match[2], ['folder', 'zip'], true)) {
						throw new BuildException($this->translate('command.usage.extract_format'));
					}
					if ($match[1] === 'type' && !in_array($match[2], ['auto', 'plugin', 'virion'], true)) {
						throw new BuildException($this->translate('command.usage.extract_type'));
					}
					$seen[$match[1]] = true;
					continue;
				}
				throw new BuildException($this->translate('command.usage.selection', ['command' => $command]));
			}
		} elseif ($command === 'doctor') {
			if (count($arguments) > 1 || (isset($arguments[0]) && str_starts_with($arguments[0], '--'))) {
				throw new BuildException($this->translate('command.usage.doctor'));
			}
		} elseif (in_array($command, ['status', 'virions', 'help'], true) && $arguments !== []) {
			throw new BuildException($this->translate('command.usage.no_arguments', ['command' => $command]));
		}
	}

	private function status(CommandSender $sender) : bool
	{
		$projects = $this->pluginDiscovery->discover($this->pluginsDirectory);
		$loaded = $this->virions->loaded();
		$async = $loaded === [] ? 'not needed yet' : ($loaded[0]->asyncSupported ? 'available' : 'not registered');
		$sender->sendMessage($this->translate('status.summary', [
			'version' => $this->plugin->getDescription()->getVersion(),
			'projects' => count($projects),
			'virions' => count($loaded),
			'async' => $async,
			'job' => $this->jobs->status(),
			'output' => $this->buildDirectory,
		]));

		return true;
	}

	/** @param list<string> $arguments */
	private function doctor(CommandSender $sender, array $arguments) : bool
	{
		$selection = array_shift($arguments);
		return $this->submit($sender, 'doctor', ['selection' => $selection], $selection === null ? 'all plugin folders' : "plugin {$selection}");
	}

	/** @param list<string> $arguments */
	private function build(CommandSender $sender, array $arguments) : bool
	{
		$target = 'plugin';
		if (isset($arguments[0]) && in_array(strtolower($arguments[0]), ['plugin', 'virion', 'both'], true)) {
			$target = strtolower((string) array_shift($arguments));
		}
		$selection = array_shift($arguments);
		if ($selection === null) {
			$sender->sendMessage($this->translate('command.build_missing'));

			return true;
		}
		$overwrite = in_array('--overwrite', $arguments, true);
		return $this->submit($sender, 'build', [
			'target' => $target,
			'selection' => $selection,
			'overwrite' => $overwrite,
		], "{$target} build for {$selection}");
	}

	/** @param list<string> $arguments */
	private function extract(CommandSender $sender, array $arguments) : bool
	{
		$selection = array_shift($arguments);
		if ($selection === null) {
			$sender->sendMessage($this->translate('command.extract_missing'));

			return true;
		}
		$overwrite = in_array('--overwrite', $arguments, true);
		$format = 'folder';
		$type = 'auto';
		foreach ($arguments as $argument) {
			if (str_starts_with($argument, '--format=')) {
				$format = substr($argument, 9);
			} elseif (str_starts_with($argument, '--type=')) {
				$type = substr($argument, 7);
			}
		}
		return $this->submit($sender, 'extract', [
			'selection' => $selection,
			'format' => $format,
			'type' => $type,
			'overwrite' => $overwrite,
		], "extract {$selection}");
	}

	private function virions(CommandSender $sender) : bool
	{
		$entries = $this->virions->entries();
		if ($entries === []) {
			$sender->sendMessage($this->translate('virions.none', ['directory' => $this->virionsDirectory]));

			return true;
		}
		foreach ($entries as $entry) {
			$name = $entry->manifest === null ? basename($entry->location) : $entry->manifest->name;
			$status = $this->translate('virions.status.' . $entry->status->value);
			$message = $entry->message;
			if ($entry->status === VirionStatus::LOADED && $entry->manifest !== null) {
				$message = $this->translate('virions.loaded', ['name' => $name, 'version' => $entry->manifest->version, 'antigen' => $entry->manifest->antigen]);
			}
			$sender->sendMessage("{$status} {$name}. {$message}");
		}

		return true;
	}

	private function help(CommandSender $sender) : bool
	{
		$sender->sendMessage($this->translate('command.help'));

		return true;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function submit(CommandSender $sender, string $operation, array $payload, string $description) : bool
	{
		if ($this->jobs->isBusy()) {
			$this->debug?->debug("Rejected {$operation} command because another background job is running.");
			$sender->sendMessage($this->translate('queue.busy'));

			return true;
		}
		$sender->sendMessage($this->translate('queue.queued', ['description' => $description]));
		$this->debug?->debug("Submitting {$operation} command for {$description}.");
		$debug = $this->debug;
		if (!$this->jobs->submit($operation, $payload, static function (array $result) use ($sender, $debug) : void {
			foreach ($result['debug'] ?? [] as $message) {
				$debug?->debug($message);
			}
			foreach ($result['messages'] as $message) {
				$sender->sendMessage($message);
			}
		})) {
			$this->debug?->debug("Failed to queue {$operation} command for {$description}.");
			$sender->sendMessage($this->translate('queue.busy'));
		}

		return true;
	}

	/** @param array<string, int|string> $parameters */
	private function translate(string $key, array $parameters = []) : string
	{
		return ($this->translator ?? Translator::fromResources())->translate($key, $parameters);
	}
}
