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
use AxolotlBucketLabs\DevTools\Runtime\PluginDependencyCollector;
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
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use AxolotlBucketLabs\DevTools\Virion\VirionStartupReporter;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use Throwable;
use function dirname;
use function is_string;

final class DevTools extends PluginBase
{
	private ?DevToolsCommandHandler $commandHandler = null;
	private ?BuildJobRunner $jobRunner = null;
	private ?Translator $translator = null;

	protected function onLoad() : void
	{
		$this->saveDefaultConfig();
		$debug = new DebugLogger((bool) $this->getConfig()->getNested('logging.debug', false)
			? function (string $message) : void {
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
		foreach ($sourceConflictDetector->detect($pluginsDirectory, $ownSourceProject, $this->translator) as $warning) {
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
		$dependencyCollector = new PluginDependencyCollector(
			$pluginDiscovery,
			$manifestReader,
			$sharedConfigResolver,
			new VirionRequirementChecker($registry, $selector),
			function (string $message) : void {
				$this->getLogger()->warning($message);
			},
			$this->translator,
			$debug,
		);
		$dependencies = $dependencyCollector->collect($pluginsDirectory, $virionsDirectory);
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
		$manager->discoverAndLoad($virionsDirectory, $dependencies->requirements(), $sharedConfigResolver->aliases(), $sharedConfigResolver->skippedLocations());

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
		$virionConflictChecker = static function (string $root) use ($sourceConflictDetector, $sharedVirionAntigens, $sharedVirionClasses) : array {
			return $sourceConflictDetector->detectVirionConflicts($root, $sharedVirionAntigens, $sharedVirionClasses);
		};

		$server->getPluginManager()->registerInterface(
			new FolderPluginLoader(
				$server->getLoader(),
				$manifestReader,
				$validator,
				$ownSourceProject,
				$virionConflictChecker,
				$dependencies,
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

	private function createTranslator(Server $server) : Translator
	{
		$configuredLocale = $this->getConfig()->get('language', Translator::AUTO_LOCALE);
		try {
			return Translator::fromResources(Translator::resolve(
				is_string($configuredLocale) ? $configuredLocale : Translator::AUTO_LOCALE,
				$server->getLanguage()->getLang(),
			));
		} catch (Throwable $error) {
			$this->getLogger()->warning(Translator::fromResources()->translate('runtime.language_fallback', ['error' => $error->getMessage()]));

			return Translator::fromResources();
		}
	}

	protected function onEnable() : void
	{
		$this->getLogger()->info(($this->translator ?? Translator::fromResources())->translate('runtime.ready'));
	}

	protected function onDisable() : void
	{
		$this->jobRunner?->shutdown();
		PhpParserNamespaceLoader::unregister();
	}

	/** @param list<string> $args */
	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool
	{
		if ($this->commandHandler === null) {
			$sender->sendMessage(($this->translator ?? Translator::fromResources())->translate('runtime.not_started'));

			return true;
		}

		return $this->commandHandler->handle($sender, $label, $args);
	}

	private function registerBuildDependencyAutoloader() : void
	{
		$base = dirname(__DIR__) . '/vendor/nikic/php-parser/lib/PhpParser';
		PhpParserNamespaceLoader::register($base);
	}
}
