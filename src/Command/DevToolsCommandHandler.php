<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

use function array_shift;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;

use function count;

use pocketmine\command\CommandSender;
use pocketmine\plugin\Plugin;

use function preg_match;
use function strtolower;
use function substr;

use Throwable;

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
    ) {}

    /** @param list<string> $arguments */
    public function handle(CommandSender $sender, string $label, array $arguments): bool
    {
        $label = strtolower(explode(':', $label, 2)[1] ?? $label);
        if ($label === 'makeplugin') {
            array_unshift($arguments, 'build');
        } elseif ($label === 'extractplugin') {
            array_unshift($arguments, 'extract');
        }

        $subcommand = strtolower(array_shift($arguments) ?? 'help');
        try {
            $this->validateArguments($subcommand, $arguments);
            if ($subcommand !== 'help'
                && !$sender->hasPermission("devtools.command.{$subcommand}")
                && !$sender->hasPermission('devtools.command')) {
                $sender->sendMessage("You do not have permission to use /devtools {$subcommand}.");

                return true;
            }
            if (in_array($subcommand, ['doctor', 'build', 'extract'], true) && !($this->enabledCommands[$subcommand] ?? true)) {
                $sender->sendMessage("The /devtools {$subcommand} command is disabled by configuration.");

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
            $sender->sendMessage("DevTools could not complete the command.\n{$error->getMessage()}");

            return true;
        }
    }

    /** @param list<string> $arguments */
    private function validateArguments(string $command, array $arguments): void
    {
        if (in_array($command, ['build', 'extract'], true)) {
            if ($command === 'build' && isset($arguments[0]) && in_array(strtolower($arguments[0]), ['plugin', 'virion', 'both'], true)) {
                array_shift($arguments);
            }
            if ($arguments === [] || str_starts_with($arguments[0], '--')) {
                throw new BuildException("Use /devtools {$command} <selection> [options]. Unknown or duplicate arguments are not accepted.");
            }
            $seen = [];
            foreach (array_slice($arguments, 1) as $argument) {
                if ($argument === '--overwrite') {
                    if (isset($seen['overwrite'])) {
                        throw new BuildException("Use /devtools {$command} <selection> [options]. Unknown or duplicate arguments are not accepted.");
                    }
                    $seen['overwrite'] = true;
                    continue;
                }
                if ($command === 'extract' && preg_match('/^--(format|type)=(folder|zip|auto|plugin|virion|both)$/D', $argument, $match) === 1) {
                    if (isset($seen[$match[1]])) {
                        throw new BuildException('Use /devtools extract <phar> [--format=folder|zip] [--type=auto|plugin|virion]. Unknown or duplicate arguments are not accepted.');
                    }
                    if ($match[1] === 'format' && !in_array($match[2], ['folder', 'zip'], true)) {
                        throw new BuildException('Use /devtools extract <phar> [--format=folder|zip].');
                    }
                    if ($match[1] === 'type' && !in_array($match[2], ['auto', 'plugin', 'virion'], true)) {
                        throw new BuildException('Use /devtools extract <phar> [--type=auto|plugin|virion].');
                    }
                    $seen[$match[1]] = true;
                    continue;
                }
                throw new BuildException("Use /devtools {$command} <selection> [options]. Unknown or duplicate arguments are not accepted.");
            }
        } elseif ($command === 'doctor') {
            if (count($arguments) > 1 || (isset($arguments[0]) && str_starts_with($arguments[0], '--'))) {
                throw new BuildException('Use /devtools doctor [plugin]. Extra arguments are not accepted.');
            }
        } elseif (in_array($command, ['status', 'virions', 'help'], true) && $arguments !== []) {
            throw new BuildException("Use /devtools {$command}. This command takes no arguments.");
        }
    }

    private function status(CommandSender $sender): bool
    {
        $projects = $this->pluginDiscovery->discover($this->pluginsDirectory);
        $loaded = $this->virions->loaded();
        $async = $loaded === [] ? 'not needed yet' : ($loaded[0]->asyncSupported ? 'available' : 'not registered');
        $sender->sendMessage(
            "DevTools {$this->plugin->getDescription()->getVersion()}\n"
            . 'The server found ' . count($projects) . " development plugin folders.\n"
            . 'The server loaded ' . count($loaded) . " shared virions.\n"
            . "Async worker class loading is {$async}.\n"
            . "The background job is {$this->jobs->status()}.\n"
            . "The build output is {$this->buildDirectory}.",
        );

        return true;
    }

    /** @param list<string> $arguments */
    private function doctor(CommandSender $sender, array $arguments): bool
    {
        $selection = array_shift($arguments);
        return $this->submit($sender, 'doctor', ['selection' => $selection], $selection === null ? 'all plugin folders' : "plugin {$selection}");
    }

    /** @param list<string> $arguments */
    private function build(CommandSender $sender, array $arguments): bool
    {
        $target = 'plugin';
        if (isset($arguments[0]) && in_array(strtolower($arguments[0]), ['plugin', 'virion', 'both'], true)) {
            $target = strtolower((string) array_shift($arguments));
        }
        $selection = array_shift($arguments);
        if ($selection === null) {
            $sender->sendMessage('Use /devtools build [plugin|virion|both] <project> [--overwrite].');

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
    private function extract(CommandSender $sender, array $arguments): bool
    {
        $selection = array_shift($arguments);
        if ($selection === null) {
            $sender->sendMessage('Use /devtools extract <phar> [--overwrite].');

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

    private function virions(CommandSender $sender): bool
    {
        $entries = $this->virions->entries();
        if ($entries === []) {
            $sender->sendMessage("No virions were found in {$this->virionsDirectory}.");

            return true;
        }
        foreach ($entries as $entry) {
            $name = $entry->manifest === null ? basename($entry->location) : $entry->manifest->name;
            $sender->sendMessage(ucfirst($entry->status->value) . " {$name}. {$entry->message}");
        }

        return true;
    }

    private function help(CommandSender $sender): bool
    {
        $sender->sendMessage(
            "DevTools commands:\n"
            . "/devtools status\n"
            . "/devtools doctor [plugin]\n"
            . "/devtools build [plugin|virion|both] <project> [--overwrite]\n"
            . "/devtools extract <phar> [--format=folder|zip] [--type=auto|plugin|virion] [--overwrite]\n"
            . '/devtools virions',
        );

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function submit(CommandSender $sender, string $operation, array $payload, string $description): bool
    {
        if ($this->jobs->isBusy()) {
            $sender->sendMessage('A DevTools background job is already running. Please wait for it to finish.');

            return true;
        }
        $sender->sendMessage("Queued DevTools {$description}.");
        if (!$this->jobs->submit($operation, $payload, static function (array $result) use ($sender): void {
            foreach ($result['messages'] as $message) {
                $sender->sendMessage($message);
            }
        })) {
            $sender->sendMessage('A DevTools background job is already running. Please wait for it to finish.');
        }

        return true;
    }
}
