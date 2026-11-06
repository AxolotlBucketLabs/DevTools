<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\PhpParserNamespaceLoader;
use AxolotlBucketLabs\DevTools\Command\BuildJobRunner;
use AxolotlBucketLabs\DevTools\Command\DevToolsCommandHandler;
use AxolotlBucketLabs\DevTools\Command\ThreadBuildJobRunner;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginLoader;
use AxolotlBucketLabs\DevTools\Loader\PluginSourceConflictDetector;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;
use AxolotlBucketLabs\DevTools\Validation\PluginProjectValidator;
use AxolotlBucketLabs\DevTools\Virion\PocketMineClassLoaderRegistrar;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManager;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use Throwable;

final class DevTools extends PluginBase
{
    private ?DevToolsCommandHandler $commandHandler = null;
    private ?BuildJobRunner $jobRunner = null;

    protected function onLoad(): void
    {
        $this->saveDefaultConfig();
        $this->registerBuildDependencyAutoloader();

        $filesystem = new Filesystem();
        $yaml = new YamlReader();
        $sourceInspector = new PhpSourceInspector();
        $manifestReader = new PluginManifestReader($yaml);
        $validator = new PluginProjectValidator($manifestReader, $sourceInspector);
        $pluginDiscovery = new FolderPluginDiscovery();
        $sourceConflictDetector = new PluginSourceConflictDetector($manifestReader);

        $server = $this->getServer();
        $pluginsDirectory = $server->getPluginPath();
        $ownSourceProject = Path::join($pluginsDirectory, $this->getDescription()->getName());
        $virionsDirectory = $server->getDataPath() . 'virions';
        $buildDirectory = $server->getDataPath() . 'build';
        $filesystem->ensureDirectory($virionsDirectory);
        $filesystem->ensureDirectory($buildDirectory);
        foreach ($sourceConflictDetector->detect($pluginsDirectory) as $warning) {
            $this->getLogger()->warning($warning);
        }

        $virionManifestReader = new VirionManifestReader($yaml);
        $virionFactory = new VirionProjectFactory($virionManifestReader);
        $virionDiscovery = new VirionDiscovery($filesystem);
        $virionScanner = new VirionClassScanner($sourceInspector);
        $registry = new VirionRegistry();
        $selector = new VirionProjectSelector();
        $configResolver = new BuildConfigResolver($yaml);
        $requirements = [];
        /** @var array<string, string> $configurationFailures */
        $configurationFailures = [];
        $normalizeProjectPath = static function (string $path): string {
            $resolved = realpath($path) ?: $path;
            $normalized = str_replace('\\', '/', $resolved);

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
        };
        foreach ($pluginDiscovery->discover($pluginsDirectory) as $project) {
            try {
                $manifest = $manifestReader->read($project . DIRECTORY_SEPARATOR . 'plugin.yml');
            } catch (Throwable $error) {
                $this->getLogger()->warning(
                    "Cannot read plugin manifest for {$project}: {$error->getMessage()}",
                );

                continue;
            }
            try {
                foreach ($configResolver->resolve($project, $manifest->description->getName())->virions as $requirement) {
                    $requirements[] = $requirement;
                }
            } catch (Throwable $error) {
                $configurationFailures[$normalizeProjectPath($project)] = $error->getMessage();
                $this->getLogger()->warning(
                    "Cannot read shared-virion requirements for {$project}: {$error->getMessage()}",
                );
            }
        }
        $manager = new VirionManager(
            $virionDiscovery,
            $virionFactory,
            $virionScanner,
            new PocketMineClassLoaderRegistrar($server->getLoader()),
            $registry,
            $selector,
            $server->getApiVersion(),
        );
        $manager->discoverAndLoad($virionsDirectory, $requirements);

        $sharedVirionAntigens = [];
        $sharedVirionClasses = [];
        foreach ($registry->loaded() as $entry) {
            if ($entry->manifest !== null) {
                $sharedVirionAntigens[] = $entry->manifest->antigen;
            }
            foreach ($entry->classes as $class) {
                $sharedVirionClasses[] = $class;
            }
        }
        $virionConflictChecker = static function (string $root) use ($sourceConflictDetector, $sharedVirionAntigens, $sharedVirionClasses): array {
            return $sourceConflictDetector->detectVirionConflicts($root, $sharedVirionAntigens, $sharedVirionClasses);
        };
        $configurationFailureChecker = static function (string $root) use (&$configurationFailures, $normalizeProjectPath): ?string {
            return $configurationFailures[$normalizeProjectPath($root)] ?? null;
        };

        $server->getPluginManager()->registerInterface(
            new FolderPluginLoader(
                $server->getLoader(),
                $manifestReader,
                $validator,
                $ownSourceProject,
                $virionConflictChecker,
                $configurationFailureChecker,
            ),
        );

        $this->jobRunner = new ThreadBuildJobRunner($server, $pluginsDirectory, $virionsDirectory, $buildDirectory);
        $this->commandHandler = new DevToolsCommandHandler(
            $this,
            $pluginDiscovery,
            $registry,
            $pluginsDirectory,
            $virionsDirectory,
            $buildDirectory,
            $this->jobRunner,
            [
                'doctor' => (bool) $this->getConfig()->getNested('commands.doctor', true),
                'build' => (bool) $this->getConfig()->getNested('commands.build', true),
                'extract' => (bool) $this->getConfig()->getNested('commands.extract', true),
            ],
        );

        foreach ($registry->entries() as $entry) {
            if ($entry->status->value === 'loaded') {
                $this->getLogger()->info($entry->message);
            } else {
                $this->getLogger()->warning($entry->message);
            }
        }
    }

    protected function onEnable(): void
    {
        $this->getLogger()->info('Folder plugin loading, shared virions, and standalone PHAR building are ready.');
    }

    protected function onDisable(): void
    {
        $this->jobRunner?->shutdown();
        PhpParserNamespaceLoader::unregister();
    }

    /** @param list<string> $args */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if ($this->commandHandler === null) {
            $sender->sendMessage('DevTools did not finish starting. Check the server log for the startup error.');

            return true;
        }

        return $this->commandHandler->handle($sender, $label, $args);
    }

    private function registerBuildDependencyAutoloader(): void
    {
        $base = dirname(__DIR__) . '/vendor/nikic/php-parser/lib/PhpParser';
        PhpParserNamespaceLoader::register($base);
    }
}
