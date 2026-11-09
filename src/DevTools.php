<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\PhpParserNamespaceLoader;
use AxolotlBucketLabs\DevTools\Build\SharedVirionConfigResolver;
use AxolotlBucketLabs\DevTools\Command\BuildJobRunner;
use AxolotlBucketLabs\DevTools\Command\DevToolsCommandHandler;
use AxolotlBucketLabs\DevTools\Command\ThreadBuildJobRunner;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginLoader;
use AxolotlBucketLabs\DevTools\Loader\PluginSourceConflictDetector;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\Translator;
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
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use AxolotlBucketLabs\DevTools\Virion\VirionStartupReporter;

use function is_string;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use Throwable;

final class DevTools extends PluginBase
{
    private ?DevToolsCommandHandler $commandHandler = null;
    private ?BuildJobRunner $jobRunner = null;
    private ?Translator $translator = null;

    protected function onLoad(): void
    {
        $this->saveDefaultConfig();
        $debug = new DebugLogger((bool) $this->getConfig()->getNested('logging.debug', false)
            ? function (string $message): void {
                $this->getLogger()->debug($message);
            }
            : null);
        $debug->debug('Starting DevTools runtime initialization.');
        $this->registerBuildDependencyAutoloader();

        $filesystem = new Filesystem();
        $yaml = new YamlReader();
        $sourceInspector = new PhpSourceInspector();
        $manifestReader = new PluginManifestReader($yaml);
        $validator = new PluginProjectValidator($manifestReader, $sourceInspector);
        $pluginDiscovery = new FolderPluginDiscovery();
        $sourceConflictDetector = new PluginSourceConflictDetector($manifestReader, null, $debug);

        $server = $this->getServer();
        $this->translator = $this->createTranslator($server);
        $pluginsDirectory = $server->getPluginPath();
        $ownSourceProject = Path::join($pluginsDirectory, $this->getDescription()->getName());
        $virionsDirectory = $server->getDataPath() . 'virions';
        $buildDirectory = $server->getDataPath() . 'build';
        $filesystem->ensureDirectory($virionsDirectory);
        $filesystem->ensureDirectory($buildDirectory);
        foreach ($sourceConflictDetector->detect($pluginsDirectory, $ownSourceProject) as $warning) {
            $this->getLogger()->warning($warning);
        }

        $virionManifestReader = new VirionManifestReader($yaml);
        $virionFactory = new VirionProjectFactory($virionManifestReader);
        $virionDiscovery = new VirionDiscovery($filesystem);
        $virionScanner = new VirionClassScanner($sourceInspector);
        $registry = new VirionRegistry();
        $selector = new VirionProjectSelector();
        $configResolver = new BuildConfigResolver($yaml);
        $sharedConfigResolver = new SharedVirionConfigResolver(
            $configResolver,
            $virionDiscovery,
            $virionFactory,
            (bool) $this->getConfig()->getNested('virions.allow-local-composer', true),
            $debug,
            $this->translator,
        );
        $pluginRequirements = $this->collectPluginRequirements(
            $pluginDiscovery,
            $manifestReader,
            $sharedConfigResolver,
            $pluginsDirectory,
            $virionsDirectory,
            $debug,
            $this->translator,
        );
        $registry = $this->loadSharedVirions(
            $server,
            $virionsDirectory,
            $pluginRequirements['requirements'],
            $sharedConfigResolver,
            $virionDiscovery,
            $virionFactory,
            $virionScanner,
            $registry,
            $selector,
            $debug,
        );

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
        $configurationFailures = $pluginRequirements['configurationFailures'];
        $configurationFailureChecker = static function (string $root) use (&$configurationFailures): ?string {
            return $configurationFailures[DevTools::normalizeProjectPath($root)] ?? null;
        };
        $requirementChecker = new VirionRequirementChecker($registry, $selector);
        $dependencyFailureChecker = static function (string $root) use ($pluginRequirements, $requirementChecker): ?string {
            return $requirementChecker->failure($pluginRequirements['requirementsByProject'][DevTools::normalizeProjectPath($root)] ?? []);
        };

        $server->getPluginManager()->registerInterface(
            new FolderPluginLoader(
                $server->getLoader(),
                $manifestReader,
                $validator,
                $ownSourceProject,
                $virionConflictChecker,
                $configurationFailureChecker,
                $dependencyFailureChecker,
                $debug,
            ),
        );

        $this->jobRunner = new ThreadBuildJobRunner($server, $pluginsDirectory, $virionsDirectory, $buildDirectory, $debug, $this->translator->locale());
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
            $debug,
            $this->translator,
        );

        foreach ((new VirionStartupReporter($this->translator))->messages($registry) as $message) {
            if ($message['level'] === 'info') {
                $this->getLogger()->info($message['message']);
            } else {
                $this->getLogger()->warning($message['message']);
            }
            foreach ($message['details'] as $detail) {
                $debug->debug($detail);
            }
        }
        $debug->debug('DevTools runtime initialization completed.');
    }

    private function createTranslator(Server $server): Translator
    {
        $configuredLocale = $this->getConfig()->get('language', 'auto');
        try {
            return Translator::fromResources(Translator::resolve(
                is_string($configuredLocale) ? $configuredLocale : 'auto',
                $server->getLanguage()->getLang(),
            ));
        } catch (Throwable $error) {
            $this->getLogger()->warning("Cannot use configured DevTools language. {$error->getMessage()} Falling back to English.");

            return Translator::fromResources();
        }
    }

    /**
     * @return array{
     *     requirements: list<VirionRequirement>,
     *     requirementsByProject: array<string, list<VirionRequirement>>,
     *     configurationFailures: array<string, string>
     * }
     */
    private function collectPluginRequirements(
        FolderPluginDiscovery $pluginDiscovery,
        PluginManifestReader $manifestReader,
        SharedVirionConfigResolver $sharedConfigResolver,
        string $pluginsDirectory,
        string $virionsDirectory,
        DebugLogger $debug,
        Translator $translator,
    ): array {
        $requirements = [];
        $requirementsByProject = [];
        $configurationFailures = [];
        $pluginProjects = $pluginDiscovery->discover($pluginsDirectory);
        $debug->debug('Discovered ' . count($pluginProjects) . ' development plugin folder(s).');
        foreach ($pluginProjects as $project) {
            try {
                $manifest = $manifestReader->read($project . DIRECTORY_SEPARATOR . 'plugin.yml');
            } catch (Throwable $error) {
                $this->getLogger()->warning(
                    "Cannot read plugin manifest for {$project}. {$error->getMessage()}",
                );

                continue;
            }
            try {
                $projectRequirements = $sharedConfigResolver->resolve($project, $manifest->description->getName(), $virionsDirectory);
                $requirementsByProject[self::normalizeProjectPath($project)] = $projectRequirements;
                $requirements = array_merge($requirements, $projectRequirements);
            } catch (Throwable $error) {
                $configurationFailures[self::normalizeProjectPath($project)] = $error->getMessage();
                $this->getLogger()->warning(
                    $translator->translate('runtime.shared_requirements_failed', ['project' => $project, 'error' => $error->getMessage()]),
                );
            }
        }

        return [
            'requirements' => $requirements,
            'requirementsByProject' => $requirementsByProject,
            'configurationFailures' => $configurationFailures,
        ];
    }

    /**
     * @param list<VirionRequirement> $requirements
     */
    private function loadSharedVirions(
        Server $server,
        string $virionsDirectory,
        array $requirements,
        SharedVirionConfigResolver $sharedConfigResolver,
        VirionDiscovery $virionDiscovery,
        VirionProjectFactory $virionFactory,
        VirionClassScanner $virionScanner,
        VirionRegistry $registry,
        VirionProjectSelector $selector,
        DebugLogger $debug,
    ): VirionRegistry {
        $manager = new VirionManager(
            $virionDiscovery,
            $virionFactory,
            $virionScanner,
            new PocketMineClassLoaderRegistrar($server->getLoader()),
            $registry,
            $selector,
            $server->getApiVersion(),
            $debug,
        );
        $manager->discoverAndLoad($virionsDirectory, $requirements, $sharedConfigResolver->aliases(), $sharedConfigResolver->skippedLocations());

        return $registry;
    }

    private static function normalizeProjectPath(string $path): string
    {
        $resolved = realpath($path) ?: $path;
        $normalized = str_replace('\\', '/', $resolved);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    protected function onEnable(): void
    {
        $this->getLogger()->info($this->translator?->translate('runtime.ready') ?? 'Folder plugin loading, shared virions, and standalone PHAR building are ready.');
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
            $sender->sendMessage($this->translator?->translate('runtime.not_started') ?? 'DevTools did not finish starting. Check the server log for the startup error.');

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
