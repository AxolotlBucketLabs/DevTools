<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Virion;

use NhanAZ\DevTools\Support\PhpSourceInspector;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Tests\TestCase;
use NhanAZ\DevTools\Virion\ClassLoaderRegistrar;
use NhanAZ\DevTools\Virion\PocketMineClassLoaderRegistrar;
use NhanAZ\DevTools\Virion\VirionClassScanner;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionManager;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionProjectSelector;
use NhanAZ\DevTools\Virion\VirionRegistry;
use NhanAZ\DevTools\Virion\VirionRequirement;
use NhanAZ\DevTools\Virion\VirionStatus;
use Phar;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use pocketmine\thread\ThreadSafeClassLoader;

final class VirionManagerTest extends TestCase
{
    public function test_missing_single_composer_package_reports_failure_for_empty_directory(): void
    {
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();
        $this->manager($registrar, $registry)->discoverAndLoad($this->temporaryDirectory . '/virions', [
            new VirionRequirement('MissingPackage', '1.0.0', 'plugin/composer.lock', str_repeat('a', 64)),
        ]);
        self::assertSame([], $registrar->registrations);
        self::assertCount(1, $registry->entries());
        self::assertStringContainsString('MissingPackage is missing', $registry->entries()[0]->message);
    }

    public function test_single_composer_package_with_changed_source_is_never_registered(): void
    {
        $location = $this->copyFixture('SharedVirion', 'virions/Shared');
        $packageHash = str_repeat('a', 64);
        file_put_contents($location . '/devtools-provenance.json', json_encode([
            'composerPackageSha256' => $packageHash,
            'sourceSha256' => str_repeat('b', 64),
            'manifestSha256' => hash_file('sha256', $location . '/virion.yml'),
        ], JSON_THROW_ON_ERROR));
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();
        $this->manager($registrar, $registry)->discoverAndLoad($this->temporaryDirectory . '/virions', [
            new VirionRequirement('SharedVirion', '1.0.0', 'plugin/composer.lock', $packageHash),
        ]);
        self::assertSame([], $registrar->registrations);
        self::assertSame([], $registry->loaded());
        self::assertStringContainsString('changed after preparation', $registry->entries()[0]->message);
    }

    public function test_changed_prepared_manifest_is_never_registered(): void
    {
        $location = $this->copyFixture('SharedVirion', 'virions/Shared');
        $packageHash = str_repeat('a', 64);
        file_put_contents($location . '/devtools-provenance.json', json_encode([
            'composerPackageSha256' => $packageHash,
            'sourceSha256' => (new \NhanAZ\DevTools\Virion\VirionSourceFingerprint())->hash($location . '/src'),
            'manifestSha256' => hash_file('sha256', $location . '/virion.yml'),
        ], JSON_THROW_ON_ERROR));
        file_put_contents($location . '/virion.yml', "composer-platform: {php: '*'}\n", FILE_APPEND);
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();
        $this->manager($registrar, $registry)->discoverAndLoad($this->temporaryDirectory . '/virions', [new VirionRequirement('SharedVirion', '1.0.0', 'plugin/composer.lock', $packageHash)]);

        self::assertSame([], $registrar->registrations);
        self::assertSame([], $registry->loaded());
        self::assertStringContainsString('Prepared manifest', $registry->entries()[0]->message);
    }

    public function test_transitive_dependency_failure_prevents_shared_graph_registration(): void
    {
        $location = $this->copyFixture('SharedVirion', 'virions/Shared');
        file_put_contents($location . '/virion.yml', "virions: [MissingDependency]\n", FILE_APPEND);
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();
        $this->manager($registrar, $registry)->discoverAndLoad($this->temporaryDirectory . '/virions');
        self::assertSame([], $registrar->registrations);
        self::assertStringContainsString('MissingDependency is missing', $registry->entries()[0]->message);
    }

    public function test_failed_member_of_dependency_cycle_prevents_cycle_registration(): void
    {
        $virions = $this->temporaryDirectory . '/virions';
        $a = $this->copyFixture('SharedVirion', 'virions/A');
        $b = $this->copyFixture('SharedVirion', 'virions/B');
        $this->filesystem->removeTree($a . '/src');
        $this->filesystem->removeTree($b . '/src');
        $this->filesystem->ensureDirectory($a . '/src/Cycle/A');
        $this->filesystem->ensureDirectory($b . '/src/Cycle/B');
        file_put_contents($a . '/src/Cycle/A/Wrong.php', "<?php\nnamespace Cycle\\A;\nfinal class Base {}\n");
        file_put_contents($b . '/src/Cycle/B/Child.php', "<?php\nnamespace Cycle\\B;\nfinal class Child {}\n");
        file_put_contents($a . '/virion.yml', "name: A\nversion: 1.0.0\nantigen: Cycle\\A\napi: 5.0.0\nvirions:\n  - B\n");
        file_put_contents($b . '/virion.yml', "name: B\nversion: 1.0.0\nantigen: Cycle\\B\napi: 5.0.0\nvirions:\n  - A\n");
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();

        $this->manager($registrar, $registry)->discoverAndLoad($virions, [
            new VirionRequirement('A', '*', 'cycle-test'),
        ]);

        self::assertSame([], $registrar->registrations);
        self::assertSame([], $registry->loaded());
        self::assertCount(2, $registry->entries());
        self::assertStringContainsString('expected', strtolower($registry->entries()[0]->message));
        self::assertStringContainsString('dependency', strtolower($registry->entries()[1]->message));
    }

    public function test_conflicting_member_of_dependency_cycle_prevents_partial_registration(): void
    {
        $virions = $this->temporaryDirectory . '/virions';
        $a = $this->copyFixture('SharedVirion', 'virions/A');
        $b = $this->copyFixture('SharedVirion', 'virions/B');
        $this->filesystem->removeTree($a . '/src');
        $this->filesystem->removeTree($b . '/src');
        $this->filesystem->ensureDirectory($a . '/src/CycleConflict/A');
        $this->filesystem->ensureDirectory($b . '/src/CycleConflict/A/Sub');
        file_put_contents($a . '/src/CycleConflict/A/Base.php', "<?php\nnamespace CycleConflict\\A;\nfinal class Base {}\n");
        file_put_contents($b . '/src/CycleConflict/A/Sub/Child.php', "<?php\nnamespace CycleConflict\\A\\Sub;\nfinal class Child extends \\CycleConflict\\A\\Base {}\n");
        file_put_contents($a . '/virion.yml', "name: A\nversion: 1.0.0\nantigen: CycleConflict\\A\napi: 5.0.0\nvirions:\n  - B\n");
        file_put_contents($b . '/virion.yml', "name: B\nversion: 1.0.0\nantigen: CycleConflict\\A\\Sub\napi: 5.0.0\nvirions:\n  - A\n");
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();

        $this->manager($registrar, $registry)->discoverAndLoad($virions, [
            new VirionRequirement('A', '*', 'cycle-conflict-test'),
        ]);

        self::assertSame([], $registrar->registrations);
        self::assertSame([], $registry->loaded());
        self::assertCount(2, $registry->entries());
        self::assertStringContainsString('conflicts', strtolower($registry->entries()[0]->message));
        self::assertStringContainsString('conflicts', strtolower($registry->entries()[1]->message));
    }

    public function test_dependency_cycle_conflict_with_external_virion_prevents_cycle_registration(): void
    {
        $virions = $this->temporaryDirectory . '/virions';
        $a = $this->copyFixture('SharedVirion', 'virions/A');
        $b = $this->copyFixture('SharedVirion', 'virions/B');
        $c = $this->copyFixture('SharedVirion', 'virions/C');
        foreach ([$a, $b, $c] as $project) {
            $this->filesystem->removeTree($project . '/src');
        }
        $this->filesystem->ensureDirectory($a . '/src/CycleAudit/Shared');
        $this->filesystem->ensureDirectory($b . '/src/CycleAudit/Shared/B');
        $this->filesystem->ensureDirectory($c . '/src/CycleAudit/Consumer');
        file_put_contents($a . '/src/CycleAudit/Shared/Base.php', "<?php\nnamespace CycleAudit\\Shared;\nfinal class Base {}\n");
        file_put_contents($b . '/src/CycleAudit/Shared/B/Base.php', "<?php\nnamespace CycleAudit\\Shared\\B;\nfinal class Base {}\n");
        file_put_contents($c . '/src/CycleAudit/Consumer/Consumer.php', "<?php\nnamespace CycleAudit\\Consumer;\nfinal class Consumer extends \\CycleAudit\\Shared\\B\\Base {}\n");
        file_put_contents($a . '/virion.yml', "name: A\nversion: 1.0.0\nantigen: CycleAudit\\Shared\napi: 5.0.0\n");
        file_put_contents($b . '/virion.yml', "name: B\nversion: 1.0.0\nantigen: CycleAudit\\Shared\\B\napi: 5.0.0\nvirions:\n  - C\n");
        file_put_contents($c . '/virion.yml', "name: C\nversion: 1.0.0\nantigen: CycleAudit\\Consumer\napi: 5.0.0\nvirions:\n  - B\n");
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();

        $this->manager($registrar, $registry)->discoverAndLoad($virions, [new VirionRequirement('A', '*', 'cycle-audit')]);

        self::assertCount(1, $registrar->registrations);
        self::assertCount(1, $registry->loaded());
        self::assertSame('A', $registry->loaded()[0]->requireManifest()->name);
        $statuses = [];
        foreach ($registry->entries() as $entry) {
            if ($entry->manifest !== null) {
                $statuses[$entry->manifest->name] = $entry->status;
            }
        }
        self::assertSame(VirionStatus::FAILED, $statuses['B']);
        self::assertSame(VirionStatus::FAILED, $statuses['C']);
    }

    #[RunInSeparateProcess]
    public function test_one_shared_virion_is_registered_once_and_used_by_two_plugins(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $location = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $classLoader = new ThreadSafeClassLoader();
        $manager = $this->manager(new PocketMineClassLoaderRegistrar($classLoader), new VirionRegistry());

        $registry = $manager->discoverAndLoad($virions);
        $manager->load($location);

        self::assertCount(1, $registry->loaded());
        self::assertTrue($registry->loaded()[0]->asyncSupported);
        self::assertTrue($classLoader->loadClass('Shared\Virion\Greeting'));
        require_once __DIR__ . '/../Fixtures/PluginA/UseVirion.php';
        require_once __DIR__ . '/../Fixtures/PluginB/UseVirion.php';
        self::assertSame('shared', \Fixture\PluginA\UseVirion::message());
        self::assertSame('shared', \Fixture\PluginB\UseVirion::message());
    }

    public function test_duplicate_namespace_is_rejected_as_conflict(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $this->copyFixture('SharedVirion', 'virions/A-Shared');
        $this->copyFixture('ConflictingVirion', 'virions/B-Conflict');
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();

        $this->manager($registrar, $registry)->discoverAndLoad($virions);

        self::assertCount(1, $registry->loaded());
        self::assertCount(1, $registrar->registrations);
        self::assertSame(VirionStatus::CONFLICT, $registry->entries()[1]->status);
        self::assertStringContainsString('conflicts', $registry->entries()[1]->message);
    }

    public function test_broken_folder_does_not_fall_back_to_same_name_phar(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $this->filesystem->ensureDirectory($virions . DIRECTORY_SEPARATOR . 'Shared');
        file_put_contents(
            $virions . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: Shared\nversion: 1.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
        );
        $phar = new Phar($virions . DIRECTORY_SEPARATOR . 'Shared.phar');
        $phar->startBuffering();
        $phar->addFromString('virion.yml', "name: Shared\nversion: 1.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n");
        $phar->addFromString('src/Shared/Virion/Greeting.php', "<?php\nnamespace Shared\\Virion;\nfinal class Greeting {}\n");
        $phar->setStub("<?php __HALT_COMPILER();\n");
        $phar->setSignatureAlgorithm(Phar::SHA256);
        $phar->stopBuffering();
        unset($phar);
        $registry = new VirionRegistry();
        $registrar = new FakeClassLoaderRegistrar();

        $this->manager($registrar, $registry)->discoverAndLoad($virions, [
            new VirionRequirement('Shared', '*', 'broken-folder-test'),
        ]);

        self::assertSame([], $registry->loaded());
        self::assertSame([], $registrar->registrations);
        self::assertCount(2, $registry->entries());
        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertSame(VirionStatus::SKIPPED, $registry->entries()[1]->status);
    }

    public function test_invalid_manifest_is_reported(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $this->copyFixture('InvalidVirion', 'virions/Invalid');
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($virions);

        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertStringContainsString('antigen', $registry->entries()[0]->message);
    }

    public function test_duplicate_class_inside_one_virion_is_rejected(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $project = $this->copyFixture('SharedVirion', 'virions/DuplicateClass');
        $this->filesystem->copyFile(
            $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Greeting.php',
            $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR . 'Duplicate.php',
        );
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($virions);

        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertStringContainsString('declared more than once', $registry->entries()[0]->message);
    }

    public function test_class_names_that_differ_only_by_case_are_rejected(): void
    {
        $virions = $this->temporaryDirectory . '/virions';
        $project = $this->copyFixture('SharedVirion', 'virions/CaseCollision');
        file_put_contents(
            $project . '/src/Shared/Virion/Greeting.php',
            <<<'PHP'
<?php
namespace Shared\Virion;

final class Greeting {}
final class greeting {}
PHP,
        );
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($virions);

        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertStringContainsString('differ only by case', $registry->entries()[0]->message);
    }

    public function test_class_file_path_and_case_must_match_antigen(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $project = $this->copyFixture('SharedVirion', 'virions/WrongPath');
        $source = $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Shared' . DIRECTORY_SEPARATOR . 'Virion' . DIRECTORY_SEPARATOR;
        $this->filesystem->copyFile($source . 'Greeting.php', $source . 'wrong.php');
        $this->filesystem->removeTree($source . 'Greeting.php');
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($virions);

        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertStringContainsString('expected source file', $registry->entries()[0]->message);
    }

    public function test_class_declaration_must_match_its_file_path(): void
    {
        $project = $this->copyFixture('SharedVirion', 'virions/Misplaced');
        $source = $project . '/src/Shared/Virion';
        $original = file_get_contents($source . '/Greeting.php');
        self::assertIsString($original);
        file_put_contents($source . '/Foo.php', str_replace('Greeting', 'Bar', $original));
        file_put_contents($source . '/Bar.php', str_replace('Greeting', 'Foo', $original));
        unlink($source . '/Greeting.php');
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($this->temporaryDirectory . '/virions');

        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertStringContainsString('is declared in', $registry->entries()[0]->message);
        self::assertStringContainsString('expected', $registry->entries()[0]->message);
    }

    public function test_failed_selected_dependency_prevents_consumer_registration(): void
    {
        $virions = $this->temporaryDirectory . '/virions';
        $consumer = $virions . '/AConsumer';
        $dependency = $virions . '/ZDependency';
        $this->filesystem->ensureDirectory($consumer . '/src/Audit/Consumer');
        $this->filesystem->ensureDirectory($dependency . '/src/Audit/Dependency');
        file_put_contents(
            $consumer . '/virion.yml',
            "name: AConsumer\nversion: 1.0.0\nantigen: Audit\\Consumer\napi: 5.0.0\nvirions:\n  - ZDependency\n",
        );
        file_put_contents(
            $consumer . '/src/Audit/Consumer/Consumer.php',
            "<?php\nnamespace Audit\\Consumer;\nfinal class Consumer extends \\Audit\\Dependency\\Base {}\n",
        );
        file_put_contents(
            $dependency . '/virion.yml',
            "name: ZDependency\nversion: 1.0.0\nantigen: Audit\\Dependency\napi: 5.0.0\n",
        );
        file_put_contents(
            $dependency . '/src/Audit/Dependency/Base.php',
            "<?php\nnamespace Audit\\Dependency;\nfinal class Base {\n",
        );
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();

        $this->manager($registrar, $registry)->discoverAndLoad($virions);

        self::assertSame([], $registrar->registrations);
        self::assertSame([], $registry->loaded());
        $consumerEntry = null;
        foreach ($registry->entries() as $entry) {
            if ($entry->manifest?->name === 'AConsumer') {
                $consumerEntry = $entry;
            }
        }
        self::assertNotNull($consumerEntry);
        self::assertSame(VirionStatus::FAILED, $consumerEntry->status);
        self::assertStringContainsString('dependency ZDependency failed', $consumerEntry->message);
    }

    public function test_file_in_class_path_is_reported_without_warnings(): void
    {
        $project = $this->copyFixture('SharedVirion', 'virions/WrongPath');
        file_put_contents($project . '/src/Shared/Virion/Other.php', '<?php namespace Shared\\Virion\\Nested; final class Other {}');
        file_put_contents($project . '/src/Shared/Virion/Nested', 'not a directory');
        $registry = new VirionRegistry();
        $registrar = new FakeClassLoaderRegistrar();

        $this->manager($registrar, $registry)->discoverAndLoad($this->temporaryDirectory . '/virions');

        self::assertSame([], $registrar->registrations);
        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertStringContainsString('expected source file', $registry->entries()[0]->message);
    }

    public function test_php_compatibility_list_is_preserved_and_enforced(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $project = $this->copyFixture('SharedVirion', 'virions/FuturePhp');
        file_put_contents(
            $project . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: FuturePhp\nversion: 1.0.0\nantigen: Shared\\Virion\nphp:\n  - \"99.0\"\n  - \"100.0\"\n",
        );
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($virions);

        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertSame(['99.0', '100.0'], $registry->entries()[0]->manifest?->php);
        self::assertStringContainsString('99.0, 100.0', $registry->entries()[0]->message);
    }

    public function test_class_constant_is_not_mistaken_for_a_declaration(): void
    {
        $path = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'Symbols.php';
        file_put_contents(
            $path,
            "<?php\nnamespace Fixture;\n\$name = Existing::class;\nfinal class Actual {}\n",
        );

        self::assertSame(['Fixture\\Actual'], (new PhpSourceInspector())->inspect($path)->classes);
    }

    public function test_packaged_phar_virion_is_discovered_and_registered(): void
    {
        $source = $this->copyFixture('SharedVirion', 'source/SharedVirion');
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $this->filesystem->ensureDirectory($virions);
        $path = $virions . DIRECTORY_SEPARATOR . 'SharedVirion.phar';
        $phar = new Phar($path);
        $phar->buildFromDirectory($source);
        $phar->setStub("<?php __HALT_COMPILER();\n");
        unset($phar);
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();

        $this->manager($registrar, $registry)->discoverAndLoad($virions);

        self::assertCount(1, $registry->loaded());
        self::assertCount(1, $registrar->registrations);
        self::assertStringStartsWith('phar://', str_replace('\\', '/', $registrar->registrations[0][1]));
    }

    public function test_folder_virion_wins_over_same_name_phar_and_records_a_warning(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $folder = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $source = $this->copyFixture('SharedVirion', 'source/SharedVirion');
        $this->filesystem->ensureDirectory($virions);
        $path = $virions . DIRECTORY_SEPARATOR . 'SharedVirion.phar';
        $phar = new Phar($path);
        $phar->buildFromDirectory($source);
        $phar->setStub("<?php __HALT_COMPILER();\n");
        unset($phar);
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($virions);

        self::assertCount(1, $registry->loaded());
        self::assertSame(realpath($folder), realpath($registry->loaded()[0]->location));
        self::assertCount(1, array_filter(
            $registry->entries(),
            static fn($entry): bool => $entry->status === VirionStatus::SKIPPED
                && str_contains($entry->message, 'folder')
                && str_contains($entry->message, 'packaged PHAR'),
        ));
    }

    public function test_highest_version_satisfying_all_requirements_is_loaded_once(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $versionOne = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v1');
        $versionTwo = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v2');
        file_put_contents(
            $versionOne . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: SharedVirion\nversion: 1.8.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
        );
        file_put_contents(
            $versionTwo . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: SharedVirion\nversion: 2.1.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
        );
        $registrar = new FakeClassLoaderRegistrar();
        $registry = new VirionRegistry();

        $this->manager($registrar, $registry)->discoverAndLoad(
            $virions,
            [new VirionRequirement('SharedVirion', '^1.0.0', 'FixturePlugin devtools.yml')],
        );

        self::assertCount(1, $registry->loaded());
        self::assertSame('1.8.0', $registry->loaded()[0]->requireManifest()->version);
        self::assertCount(1, $registrar->registrations);
        self::assertSame(VirionStatus::SKIPPED, $registry->entries()[1]->status);
        self::assertStringContainsString('does not satisfy ^1.0.0', $registry->entries()[1]->message);
    }

    public function test_missing_required_virion_is_visible_in_registry(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad(
            $virions,
            [new VirionRequirement('MissingVirion', '^1.0.0', 'MissingPlugin devtools.yml')],
        );

        self::assertSame(VirionStatus::FAILED, $registry->entries()[0]->status);
        self::assertStringContainsString('Required virion MissingVirion is missing', $registry->entries()[0]->message);
        self::assertStringContainsString('MissingPlugin devtools.yml', $registry->entries()[0]->message);
    }

    public function test_highest_version_is_selected_when_no_plugin_constrains_it(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $versionOne = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v1');
        $versionTwo = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v2');
        file_put_contents(
            $versionOne . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: SharedVirion\nversion: 1.9.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
        );
        file_put_contents(
            $versionTwo . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: SharedVirion\nversion: 2.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
        );
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad($virions);

        self::assertSame('2.0.0', $registry->loaded()[0]->requireManifest()->version);
        self::assertSame(VirionStatus::SKIPPED, $registry->entries()[0]->status);
        self::assertStringContainsString('higher compatible version 2.0.0', $registry->entries()[0]->message);
    }

    public function test_preloaded_class_conflict_prevents_virion_registration(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $project = $virions . DIRECTORY_SEPARATOR . 'SharedV2';
        $this->filesystem->ensureDirectory($project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Audit' . DIRECTORY_SEPARATOR . 'Preloaded');
        file_put_contents(
            $project . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: SharedV2\nversion: 2.0.0\nantigen: Audit\\Preloaded\napi: 5.0.0\n",
        );
        file_put_contents(
            $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Audit' . DIRECTORY_SEPARATOR . 'Preloaded' . DIRECTORY_SEPARATOR . 'Value.php',
            "<?php\nnamespace Audit\\Preloaded; final class Value {}\n",
        );
        $preloaded = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'preloaded.php';
        file_put_contents(
            $preloaded,
            "<?php\nnamespace Audit\\Preloaded; final class Value {}\n",
        );
        require_once $preloaded;

        $registry = new VirionRegistry();
        $registrar = new FakeClassLoaderRegistrar();
        $this->manager($registrar, $registry)->discoverAndLoad($virions, [
            new VirionRequirement('SharedV2', '2.0.0', 'preloaded-class-test'),
        ]);

        self::assertSame([], $registrar->registrations);
        self::assertSame([], $registry->loaded());
        self::assertStringContainsString('already loaded', strtolower($registry->entries()[0]->message));
    }

    public function test_wide_dependency_dag_cycle_check_does_not_repeat_shared_subgraphs(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $layers = 20;
        for ($layer = 0; $layer < $layers; ++$layer) {
            for ($branch = 0; $branch < 2; ++$branch) {
                $suffix = $branch === 0 ? 'A' : 'B';
                $name = "Bench{$layer}{$suffix}";
                $antigen = "Bench\\L{$layer}{$suffix}";
                $project = $virions . DIRECTORY_SEPARATOR . $name;
                $source = $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $antigen);
                $this->filesystem->ensureDirectory($source);
                $requirements = '';
                if ($layer < $layers - 1) {
                    $nextLayer = $layer + 1;
                    $requirements = "virions: [Bench{$nextLayer}A, Bench{$nextLayer}B]\n";
                }
                file_put_contents(
                    $project . DIRECTORY_SEPARATOR . 'virion.yml',
                    "name: {$name}\nversion: 1.0.0\nantigen: {$antigen}\napi: 5.0.0\n{$requirements}",
                );
                file_put_contents(
                    $source . DIRECTORY_SEPARATOR . 'Value.php',
                    "<?php\nnamespace {$antigen}; final class Value {}\n",
                );
            }
        }

        $registry = new VirionRegistry();
        $registrar = new FakeClassLoaderRegistrar();
        $started = hrtime(true);
        $this->manager($registrar, $registry)->discoverAndLoad($virions);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        self::assertCount($layers * 2, $registrar->registrations);
        self::assertCount($layers * 2, $registry->loaded());
        self::assertLessThan(2.0, $elapsed, "Dependency graph preflight took {$elapsed} seconds.");
    }

    public function test_incompatible_plugin_requirements_load_no_arbitrary_version(): void
    {
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $versionOne = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v1');
        $versionTwo = $this->copyFixture('SharedVirion', 'virions/SharedVirion-v2');
        file_put_contents(
            $versionOne . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: SharedVirion\nversion: 1.9.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
        );
        file_put_contents(
            $versionTwo . DIRECTORY_SEPARATOR . 'virion.yml',
            "name: SharedVirion\nversion: 2.0.0\nantigen: Shared\\Virion\napi: 5.0.0\n",
        );
        $registry = new VirionRegistry();

        $this->manager(new FakeClassLoaderRegistrar(), $registry)->discoverAndLoad(
            $virions,
            [
                new VirionRequirement('SharedVirion', '^1.0.0', 'PluginOne'),
                new VirionRequirement('SharedVirion', '^2.0.0', 'PluginTwo'),
            ],
        );

        self::assertSame([], $registry->loaded());
        self::assertSame(VirionStatus::CONFLICT, $registry->entries()[0]->status);
        self::assertSame(VirionStatus::CONFLICT, $registry->entries()[1]->status);
        self::assertStringContainsString('No version of virion SharedVirion satisfies', $registry->entries()[0]->message);
    }

    private function manager(ClassLoaderRegistrar $registrar, VirionRegistry $registry): VirionManager
    {
        $yaml = new YamlReader();

        return new VirionManager(
            new VirionDiscovery($this->filesystem),
            new VirionProjectFactory(new VirionManifestReader($yaml)),
            new VirionClassScanner(new PhpSourceInspector()),
            $registrar,
            $registry,
            new VirionProjectSelector(),
            '5.44.0',
        );
    }
}
