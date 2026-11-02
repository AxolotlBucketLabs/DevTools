<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Command;

use function array_shift;
use function basename;
use function count;
use function is_file;

use NhanAZ\DevTools\Build\ArtifactInspector;
use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\BuildException;
use NhanAZ\DevTools\Build\PharArchiveReader;
use NhanAZ\DevTools\Build\PharBuilder;
use NhanAZ\DevTools\Build\PharExtractor;
use NhanAZ\DevTools\Build\PluginProjectLocator;
use NhanAZ\DevTools\Build\ProjectRole;
use NhanAZ\DevTools\Build\ProjectRoleDetector;
use NhanAZ\DevTools\Build\VirionBuilder;
use NhanAZ\DevTools\Build\VirionProjectLocator;
use NhanAZ\DevTools\Build\VirionResolver;
use NhanAZ\DevTools\Build\ZipArchiveWriter;
use NhanAZ\DevTools\Loader\FolderPluginDiscovery;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Validation\PluginProjectValidator;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionRegistry;
use pocketmine\command\CommandSender;
use pocketmine\plugin\Plugin;

use function preg_match;
use function realpath;
use function str_ends_with;
use function strtolower;
use function substr;

use Throwable;

final class DevToolsCommandHandler
{
    public function __construct(
        private readonly Plugin $plugin,
        private readonly FolderPluginDiscovery $pluginDiscovery,
        private readonly PluginProjectValidator $validator,
        private readonly PluginManifestReader $manifestReader,
        private readonly BuildConfigResolver $configResolver,
        private readonly VirionResolver $resolver,
        private readonly PluginProjectLocator $projectLocator,
        private readonly PharBuilder $builder,
        private readonly PharExtractor $extractor,
        private readonly VirionRegistry $virions,
        private readonly string $pluginsDirectory,
        private readonly string $virionsDirectory,
        private readonly string $buildDirectory,
        private readonly ?VirionBuilder $virionBuilder = null,
        private readonly ?ZipArchiveWriter $zipWriter = null,
        private readonly ?ArtifactInspector $artifactInspector = null,
        private readonly ?ProjectRoleDetector $roleDetector = null,
        private readonly ?VirionProjectLocator $virionProjectLocator = null,
        private readonly ?VirionManifestReader $virionManifestReader = null,
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
                throw new BuildException("Usage: /devtools {$command} <selection> [options]. Unknown or duplicate arguments are not accepted.");
            }
            $seen = [];
            foreach (array_slice($arguments, 1) as $argument) {
                if ($argument === '--overwrite') {
                    if (isset($seen['overwrite'])) {
                        throw new BuildException("Usage: /devtools {$command} <selection> [options]. Unknown or duplicate arguments are not accepted.");
                    }
                    $seen['overwrite'] = true;
                    continue;
                }
                if ($command === 'extract' && preg_match('/^--(format|type)=(folder|zip|auto|plugin|virion|both)$/D', $argument, $match) === 1) {
                    if (isset($seen[$match[1]])) {
                        throw new BuildException('Usage: /devtools extract <phar> [--format=folder|zip] [--type=auto|plugin|virion]. Unknown or duplicate arguments are not accepted.');
                    }
                    if ($match[1] === 'format' && !in_array($match[2], ['folder', 'zip'], true)) {
                        throw new BuildException('Usage: /devtools extract <phar> [--format=folder|zip].');
                    }
                    if ($match[1] === 'type' && !in_array($match[2], ['auto', 'plugin', 'virion'], true)) {
                        throw new BuildException('Usage: /devtools extract <phar> [--type=auto|plugin|virion].');
                    }
                    $seen[$match[1]] = true;
                    continue;
                }
                throw new BuildException("Usage: /devtools {$command} <selection> [options]. Unknown or duplicate arguments are not accepted.");
            }
        } elseif ($command === 'doctor') {
            if (count($arguments) > 1 || (isset($arguments[0]) && str_starts_with($arguments[0], '--'))) {
                throw new BuildException('Usage: /devtools doctor [plugin]. Extra arguments are not accepted.');
            }
        } elseif (in_array($command, ['status', 'virions', 'help'], true) && $arguments !== []) {
            throw new BuildException("Usage: /devtools {$command}. This command takes no arguments.");
        }
    }

    private function status(CommandSender $sender): bool
    {
        $projects = $this->pluginDiscovery->discover($this->pluginsDirectory);
        $loaded = $this->virions->loaded();
        $async = $loaded === [] ? 'not needed yet' : ($loaded[0]->asyncSupported ? 'available' : 'not registered');
        $sender->sendMessage(
            "DevTools {$this->plugin->getDescription()->getVersion()}\n"
            . 'Development plugin folders found: ' . count($projects) . "\n"
            . 'Shared virions loaded: ' . count($loaded) . "\n"
            . "Async worker class loading: {$async}\n"
            . "Build output: {$this->buildDirectory}",
        );

        return true;
    }

    /** @param list<string> $arguments */
    private function doctor(CommandSender $sender, array $arguments): bool
    {
        $selection = array_shift($arguments);
        $projects = $selection === null
            ? $this->pluginDiscovery->discover($this->pluginsDirectory)
            : [$this->projectLocator->locate($selection, $this->pluginsDirectory)];
        if ($projects === []) {
            $sender->sendMessage("No development plugin folders were found in {$this->pluginsDirectory}.");

            return true;
        }
        foreach ($projects as $project) {
            $result = $this->validator->validate($project);
            $sender->sendMessage("Checking {$project}");
            foreach ($result->issues() as $issue) {
                $sender->sendMessage($issue->format());
            }
            if ($result->hasErrors()) {
                continue;
            }
            try {
                $manifest = $this->manifestReader->read($project . DIRECTORY_SEPARATOR . 'plugin.yml');
                $config = $this->configResolver->resolve($project, $manifest->description->getName());
                $resolved = $this->resolver->resolve($config->virions, $this->virionsDirectory);
                $sender->sendMessage('Project structure and dependency selection passed (' . count($resolved) . ' virions). Shading, static analysis and runtime tests were not run. Check /devtools virions for the shared server registry.');
            } catch (Throwable $error) {
                $sender->sendMessage("Dependency check failed for {$project}: {$error->getMessage()}");
            }
        }

        return true;
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
            $sender->sendMessage('Usage: /devtools build [plugin|virion|both] <project> [--overwrite]');

            return true;
        }
        $overwrite = in_array('--overwrite', $arguments, true);
        if ($target === 'virion') {
            if ($this->virionBuilder === null || $this->virionProjectLocator === null) {
                throw new BuildException('Virion building is not configured.');
            }
            $project = $this->virionProjectLocator->locate($selection, $this->virionsDirectory);
            $sender->sendMessage("Validating and staging virion {$project}...");
            $result = $this->virionBuilder->build($project, $this->buildDirectory, $overwrite);
            $sender->sendMessage("Built virion {$result->manifest->name} successfully.\nFiles: {$result->fileCount}\nOutput: {$result->outputPath}");

            return true;
        }

        $project = $this->projectLocator->locate($selection, $this->pluginsDirectory);
        if ($target === 'both') {
            if ($this->virionBuilder === null || $this->roleDetector === null) {
                throw new BuildException('Dual-role building is not configured.');
            }
            if ($this->roleDetector->detect($project) !== ProjectRole::DUAL) {
                throw new BuildException("Build type both requires {$project} to contain both plugin.yml and virion.yml.");
            }
            $sender->sendMessage("Validating and staging dual-role project {$project}...");
            $pluginName = $this->manifestReader->read($project . DIRECTORY_SEPARATOR . 'plugin.yml')->description->getName();
            $virionName = $this->virionManifestReader?->read($project . DIRECTORY_SEPARATOR . 'virion.yml')->name ?? basename($project);
            $plugin = $this->builder->build($project, $this->virionsDirectory, $this->buildDirectory, $overwrite, $pluginName . '-plugin');
            $virion = $this->virionBuilder->build($project, $this->buildDirectory, $overwrite, $virionName . '-virion');
            $sender->sendMessage("Built plugin {$plugin->pluginName} and virion {$virion->manifest->name} successfully.\nPlugin output: {$plugin->outputPath}\nVirion output: {$virion->outputPath}");

            return true;
        }

        $sender->sendMessage("Validating and staging {$project}...");
        $result = $this->builder->build($project, $this->virionsDirectory, $this->buildDirectory, $overwrite);
        $sender->sendMessage(
            "Built {$result->pluginName} successfully.\n"
            . "Files: {$result->fileCount}\n"
            . 'Shaded virions: ' . count($result->shadedVirions) . "\n"
            . "Output: {$result->outputPath}",
        );

        return true;
    }

    /** @param list<string> $arguments */
    private function extract(CommandSender $sender, array $arguments): bool
    {
        $selection = array_shift($arguments);
        if ($selection === null) {
            $sender->sendMessage('Usage: /devtools extract <phar> [--overwrite]');

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
        $path = $this->locatePhar($selection);
        $detectedType = $this->artifactInspector?->inspect($path)['type'] ?? 'plugin';
        if ($type === 'auto') {
            $type = $detectedType;
        }
        if ($type === 'both') {
            throw new BuildException('A dual-role artifact requires --type=plugin or --type=virion for extraction.');
        }
        if (!in_array($type, ['plugin', 'virion'], true) || !in_array($format, ['folder', 'zip'], true)) {
            throw new BuildException('Usage: /devtools extract <phar> [--format=folder|zip] [--type=auto|plugin|virion] [--overwrite]');
        }
        $name = basename($path);
        if (str_ends_with(strtolower($name), '.phar')) {
            $name = substr($name, 0, -5);
        }
        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw new BuildException("Cannot derive a safe plugin folder name from {$path}.");
        }
        try {
            Path::normalizeRelative($name);
        } catch (\RuntimeException $error) {
            throw new BuildException("Cannot derive a portable plugin folder name from {$path}: {$error->getMessage()}", 0, $error);
        }
        if ($format === 'zip') {
            if ($this->zipWriter === null) {
                throw new BuildException('ZIP conversion is not configured.');
            }
            $destination = Path::join(realpath($this->buildDirectory) ?: $this->buildDirectory, $name . '.zip');
            $count = $this->zipWriter->write(new PharArchiveReader($path), $destination, $overwrite);
            $sender->sendMessage("Archived {$count} files to {$destination}.");

            return true;
        }
        $baseDirectory = $type === 'virion' ? $this->virionsDirectory : $this->pluginsDirectory;
        $base = realpath($baseDirectory);
        if ($base === false) {
            throw new BuildException("Cannot resolve {$type} directory {$baseDirectory}.");
        }
        $destination = Path::join($base, $name);
        if (dirname($destination) !== $base) {
            throw new BuildException("Refusing extraction destination outside {$base}.");
        }
        $count = $this->extractor->extract(new PharArchiveReader($path), $destination, $overwrite);
        $sender->sendMessage("Extracted {$count} files to {$destination}.");

        return true;
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
            $sender->sendMessage(ucfirst($entry->status->value) . " {$name}: {$entry->message}");
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

    private function locatePhar(string $selection): string
    {
        $candidates = [
            $selection,
            $this->buildDirectory . DIRECTORY_SEPARATOR . $selection,
            $this->pluginsDirectory . DIRECTORY_SEPARATOR . $selection,
            $this->virionsDirectory . DIRECTORY_SEPARATOR . $selection,
        ];
        if (!str_ends_with(strtolower($selection), '.phar')) {
            $candidates[] = $this->buildDirectory . DIRECTORY_SEPARATOR . $selection . '.phar';
            $candidates[] = $this->pluginsDirectory . DIRECTORY_SEPARATOR . $selection . '.phar';
            $candidates[] = $this->virionsDirectory . DIRECTORY_SEPARATOR . $selection . '.phar';
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $real = realpath($candidate);
                if ($real !== false) {
                    return $real;
                }
            }
        }

        throw new BuildException("Cannot find PHAR {$selection}. Check the path or the build/ directory.");
    }
}
