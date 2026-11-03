<?php

declare(strict_types=1);

namespace NhanAZ\DevTools;

use Closure;

use function is_file;

use NhanAZ\DevTools\Build\ArtifactInspector;
use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\NamespaceShader;
use NhanAZ\DevTools\Build\PharBuilder;
use NhanAZ\DevTools\Build\PharExtractor;
use NhanAZ\DevTools\Build\PluginProjectLocator;
use NhanAZ\DevTools\Build\ProjectRoleDetector;
use NhanAZ\DevTools\Build\VirionBuilder;
use NhanAZ\DevTools\Build\VirionProjectLocator;
use NhanAZ\DevTools\Build\VirionResolver;
use NhanAZ\DevTools\Build\ZipArchiveWriter;
use NhanAZ\DevTools\Command\DevToolsCommandHandler;
use NhanAZ\DevTools\Loader\FolderPluginDiscovery;
use NhanAZ\DevTools\Loader\FolderPluginLoader;
use NhanAZ\DevTools\Loader\PluginSourceConflictDetector;
use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Support\PhpSourceInspector;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Validation\PluginProjectValidator;
use NhanAZ\DevTools\Virion\PocketMineClassLoaderRegistrar;
use NhanAZ\DevTools\Virion\VirionClassScanner;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionManager;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionProjectSelector;
use NhanAZ\DevTools\Virion\VirionRegistry;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;

use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_starts_with;

use Throwable;

final class DevTools extends PluginBase
{
    private ?DevToolsCommandHandler $commandHandler = null;

    /** @var (Closure(string): void)|null */
    private ?Closure $dependencyAutoloader = null;

    protected function onLoad(): void
    {
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
        foreach ($pluginDiscovery->discover($pluginsDirectory) as $project) {
            try {
                $manifest = $manifestReader->read($project . DIRECTORY_SEPARATOR . 'plugin.yml');
                foreach ($configResolver->resolve($project, $manifest->description->getName())->virions as $requirement) {
                    $requirements[] = $requirement;
                }
            } catch (Throwable $error) {
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

        $server->getPluginManager()->registerInterface(
            new FolderPluginLoader($server->getLoader(), $manifestReader, $validator, $ownSourceProject),
        );

        $builder = new PharBuilder(
            $filesystem,
            $validator,
            $manifestReader,
            $configResolver,
            new VirionResolver($virionDiscovery, $virionFactory, $selector),
            $virionScanner,
            new NamespaceShader(),
            $sourceInspector,
        );
        $this->commandHandler = new DevToolsCommandHandler(
            $this,
            $pluginDiscovery,
            $validator,
            $manifestReader,
            $configResolver,
            new VirionResolver($virionDiscovery, $virionFactory, $selector),
            new PluginProjectLocator($pluginDiscovery, $manifestReader),
            $builder,
            new PharExtractor($filesystem),
            $registry,
            $pluginsDirectory,
            $virionsDirectory,
            $buildDirectory,
            new VirionBuilder($filesystem, $virionManifestReader, $virionFactory, $virionScanner),
            new ZipArchiveWriter($filesystem),
            new ArtifactInspector($manifestReader, $virionManifestReader),
            new ProjectRoleDetector(),
            new VirionProjectLocator($virionDiscovery, $virionFactory),
            $virionManifestReader,
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
        if ($this->dependencyAutoloader !== null) {
            spl_autoload_unregister($this->dependencyAutoloader);
            $this->dependencyAutoloader = null;
        }
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
        $base = dirname(__DIR__) . '/vendor/nikic/php-parser/lib/PhpParser/';
        $this->dependencyAutoloader = static function (string $class) use ($base): void {
            $prefix = 'PhpParser\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        };
        spl_autoload_register($this->dependencyAutoloader, true, true);
    }
}
