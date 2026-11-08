<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildConfigResolver;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\ComposerVirionPlan;
use AxolotlBucketLabs\DevTools\Build\ComposerVirionPreparer;
use AxolotlBucketLabs\DevTools\Build\SharedVirionConfigResolver;
use AxolotlBucketLabs\DevTools\Support\DebugLogger;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\PocketMineClassLoaderRegistrar;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManager;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirementChecker;
use AxolotlBucketLabs\DevTools\Virion\VirionSourceFingerprint;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use pocketmine\thread\ThreadSafeClassLoader;

final class SharedVirionConfigResolverTest extends TestCase
{
    public function test_matching_local_source_loads_for_locked_composer_plugin(): void
    {
        [$project, $virions] = $this->project();
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        $registry = new VirionRegistry();
        $loader = new ThreadSafeClassLoader();
        $manager = new VirionManager(
            new VirionDiscovery($this->filesystem),
            new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
            new VirionClassScanner(new PhpSourceInspector()),
            new PocketMineClassLoaderRegistrar($loader),
            $registry,
            new VirionProjectSelector(),
            '5.51.1',
        );
        $manager->discoverAndLoad($virions, $requirements);

        self::assertCount(1, $registry->loaded());
        self::assertSame('SharedVirion', $requirements[0]->name);
        self::assertNull((new VirionRequirementChecker($registry, new VirionProjectSelector()))->failure($requirements));
        self::assertSame(
            realpath($virions . '/Library/src/Shared/Virion/Greeting.php'),
            realpath($loader->findClass('Shared\\Virion\\Greeting') ?? ''),
        );
    }

    public function test_local_source_remains_unacceptable_to_standalone_build(): void
    {
        [$project, $virions] = $this->project();
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('no valid virion with that name exists');
        $this->builder()->build($project, $virions, $project . '/build');
    }

    public function test_local_source_can_be_disabled_without_changing_build_configuration(): void
    {
        [$project, $virions] = $this->project();
        $requirements = $this->resolver(false)->resolve($project, 'BuildFixture', $virions);
        self::assertSame('example.library', $requirements[0]->name);
        self::assertNotNull($requirements[0]->composerPackageSha256);
    }

    #[DataProvider('runtimeLocales')]
    public function test_local_selection_and_metadata_errors_follow_the_runtime_locale(string $locale): void
    {
        [$project, $virions] = $this->project();
        $translator = Translator::fromResources($locale);
        $messages = [];
        $debug = new DebugLogger(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $resolver = $this->resolver(true, $debug, $translator);
        $resolver->resolve($project, 'BuildFixture', $virions);
        self::assertSame([$translator->translate('virions.local.selected_debug', [
            'name' => 'SharedVirion', 'package' => 'example/library', 'project' => $project,
        ])], $messages);
        $path = $virions . '/Library/composer.json';
        $metadata = (new ComposerVirionPlan())->readJson($path);
        $metadata['require'] = ['php' => '^8.2'];
        $this->json($path, $metadata);
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage($translator->translate('virions.local.metadata_mismatch', ['name' => 'SharedVirion', 'field' => 'require']));
        $resolver->resolve($project, 'BuildFixture', $virions);
    }

    /** @return iterable<string, array{string}> */
    public static function runtimeLocales(): iterable
    {
        foreach (Translator::supportedLocales() as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('invalidLocalPackage')]
    public function test_local_metadata_cannot_bypass_locked_identity(string $change): void
    {
        [$project, $virions] = $this->project();
        $library = $virions . '/Library';
        $metadata = (new ComposerVirionPlan())->readJson($library . '/composer.json');
        if ($change === 'require') {
            $metadata['require'] = ['php' => '^8.2'];
        } elseif ($change === 'namespace') {
            file_put_contents($library . '/virion.yml', str_replace('Shared\\Virion', 'Other\\Virion', $this->read($library . '/virion.yml')));
        } elseif ($change === 'layout') {
            $package = $this->package($project);
            $package['autoload'] = $metadata['autoload'] = ['psr-4' => ['Shared\\Virion\\' => 'src/']];
            $this->lockPackage($project, $package);
        } else {
            $metadata['extra'] = ['virion' => ['spec' => '3.1', 'namespace-root' => 'Shared\\Virion']];
        }
        $this->json($library . '/composer.json', $metadata);
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('differs from composer.lock');
        $this->resolver()->resolve($project, 'BuildFixture', $virions);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLocalPackage(): iterable
    {
        foreach (['require', 'namespace', 'layout', 'extra'] as $change) {
            yield $change => [$change];
        }
    }

    public function test_wrong_version_or_package_name_does_not_satisfy_composer_requirement(): void
    {
        [$project, $virions] = $this->project();
        file_put_contents($virions . '/Library/virion.yml', str_replace('1.0.0', '2.0.0', $this->read($virions . '/Library/virion.yml')));
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        self::assertSame('example.library', $requirements[0]->name);
        self::assertSame([], $this->load($virions, $requirements, $resolver)->loaded());
        self::assertSame([], $resolver->aliases());
    }

    public function test_canonical_local_name_still_requires_matching_composer_metadata(): void
    {
        [$project, $virions] = $this->project();
        file_put_contents($virions . '/Library/virion.yml', str_replace('SharedVirion', 'example.library', $this->read($virions . '/Library/virion.yml')));
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        self::assertNull($requirements[0]->composerPackageSha256);
        self::assertCount(1, $this->load($virions, $requirements, $resolver)->loaded());
    }

    public function test_canonical_local_source_still_requires_provenance_for_builds(): void
    {
        [$project, $virions] = $this->project();
        file_put_contents($virions . '/Library/virion.yml', str_replace('SharedVirion', 'example.library', $this->read($virions . '/Library/virion.yml')));
        $this->resolver()->resolve($project, 'BuildFixture', $virions);
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('does not match this composer.lock');
        $this->builder()->build($project, $virions, $project . '/build');
    }

    public function test_wrong_composer_package_name_does_not_create_an_alias(): void
    {
        [$project, $virions] = $this->project();
        $path = $virions . '/Library/composer.json';
        $metadata = (new ComposerVirionPlan())->readJson($path);
        $metadata['name'] = 'other/library';
        $this->json($path, $metadata);
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        self::assertNotNull($requirements[0]->composerPackageSha256);
        self::assertSame([], $resolver->aliases());
        self::assertSame([], $this->load($virions, $requirements, $resolver)->loaded());
    }

    public function test_commit_locked_branch_cannot_use_unverified_local_source(): void
    {
        [$project, $virions] = $this->project();
        $package = $this->package($project);
        $package['version'] = 'dev-main';
        $package['source'] = ['type' => 'git', 'url' => 'https://example.invalid/library', 'reference' => str_repeat('a', 40)];
        $this->lockPackage($project, $package);
        file_put_contents($virions . '/Library/virion.yml', str_replace('1.0.0', '0.0.0-commit.' . str_repeat('a', 40), $this->read($virions . '/Library/virion.yml')));
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        self::assertNotNull($requirements[0]->composerPackageSha256);
        self::assertSame([], $this->load($virions, $requirements, $resolver)->loaded());
    }

    #[DataProvider('localAliasNames')]
    public function test_incompatible_plugin_locks_do_not_load_a_shared_version(bool $differentNames): void
    {
        [$project, $virions] = $this->project();
        $second = $this->temporaryDirectory . '/SecondPlugin';
        $this->filesystem->copyTree($project, $second);
        $root = ['name' => 'example/plugin', 'require' => ['example/library' => '^2.0']];
        $this->json($second . '/composer.json', $root);
        $package = $this->package($project);
        $package['version'] = '2.0.0';
        ksort($root);
        $this->json($second . '/composer.lock', ['content-hash' => md5(json_encode($root, JSON_THROW_ON_ERROR)), 'packages' => [$package]]);
        $this->filesystem->copyTree($virions . '/Library', $virions . '/LibraryV2');
        file_put_contents($virions . '/LibraryV2/virion.yml', str_replace('1.0.0', '2.0.0', $this->read($virions . '/LibraryV2/virion.yml')));
        if ($differentNames) {
            file_put_contents($virions . '/Library/virion.yml', str_replace('SharedVirion', 'LibraryV1', $this->read($virions . '/Library/virion.yml')));
            file_put_contents($virions . '/LibraryV2/virion.yml', str_replace('SharedVirion', 'LibraryV2', $this->read($virions . '/LibraryV2/virion.yml')));
        }
        $resolver = $this->resolver();
        $requirements = array_merge($resolver->resolve($project, 'BuildFixture', $virions), $resolver->resolve($second, 'BuildFixture', $virions));
        $registry = $this->load($virions, $requirements, $resolver);
        self::assertSame([], $registry->loaded());
        self::assertStringContainsString('No version of virion ' . ($differentNames ? 'LibraryV1' : 'SharedVirion') . ' satisfies', $registry->entries()[0]->message);
        self::assertNotNull((new VirionRequirementChecker($registry, new VirionProjectSelector()))->failure($requirements));
    }

    /** @return iterable<string, array{bool}> */
    public static function localAliasNames(): iterable
    {
        yield 'same name' => [false];
        yield 'different names' => [true];
    }

    public function test_duplicate_local_source_is_rejected_before_registration(): void
    {
        [$project, $virions] = $this->project();
        $this->filesystem->copyTree($virions . '/Library', $virions . '/Duplicate');
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        $registry = $this->load($virions, $requirements, $resolver);
        self::assertSame([], $registry->loaded());
        self::assertStringContainsString('multiple locations', $registry->entries()[0]->message);
    }

    public function test_unselected_local_version_does_not_override_locked_metadata(): void
    {
        [$project, $virions] = $this->project();
        $this->filesystem->copyTree($virions . '/Library', $virions . '/LibraryV2');
        file_put_contents($virions . '/LibraryV2/virion.yml', str_replace('1.0.0', '2.0.0', $this->read($virions . '/LibraryV2/virion.yml')));
        $metadata = (new ComposerVirionPlan())->readJson($virions . '/LibraryV2/composer.json');
        $metadata['require'] = ['php' => '^99.0'];
        $this->json($virions . '/LibraryV2/composer.json', $metadata);
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        $registry = $this->load($virions, $requirements, $resolver);
        self::assertCount(1, $registry->loaded());
        self::assertSame('1.0.0', $registry->loaded()[0]->requireManifest()->version);
    }

    public function test_unverified_same_name_folder_cannot_inherit_local_validation(): void
    {
        [$project, $virions] = $this->project();
        $this->copyFixture('SharedVirion', 'virions/Impostor');
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('unverified package');
        $this->resolver()->resolve($project, 'BuildFixture', $virions);
    }

    public function test_different_virion_names_claiming_one_package_are_rejected(): void
    {
        [$project, $virions] = $this->project();
        $this->filesystem->copyTree($virions . '/Library', $virions . '/Duplicate');
        file_put_contents($virions . '/Duplicate/virion.yml', str_replace('SharedVirion', 'AnotherName', $this->read($virions . '/Duplicate/virion.yml')));
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('Multiple local virion names');
        $this->resolver()->resolve($project, 'BuildFixture', $virions);
    }

    #[DataProvider('preparedState')]
    public function test_prepared_copy_takes_precedence_and_never_falls_back_when_changed(bool $changed, bool $canonicalLocal, bool $renamedPrepared, string $manifestFailure): void
    {
        [$project, $virions] = $this->project();
        $library = $virions . '/Library';
        $prepared = $virions . '/Prepared';
        $this->filesystem->copyTree($library, $prepared);
        unlink($prepared . '/composer.json');
        file_put_contents($prepared . '/virion.yml', str_replace('SharedVirion', 'example.library', $this->read($prepared . '/virion.yml')));
        $this->json($prepared . '/devtools-provenance.json', [
            'composerPackageSha256' => (new ComposerVirionPlan())->fingerprint($this->package($project)),
            'manifestSha256' => hash_file('sha256', $prepared . '/virion.yml'),
            'sourceSha256' => (new VirionSourceFingerprint())->hash($prepared . '/src/Shared/Virion'),
        ]);
        if ($canonicalLocal) {
            file_put_contents($library . '/virion.yml', str_replace('SharedVirion', 'example.library', $this->read($library . '/virion.yml')));
        }
        if ($renamedPrepared) {
            file_put_contents($prepared . '/virion.yml', str_replace('example.library', 'RenamedPrepared', $this->read($prepared . '/virion.yml')));
        }
        if ($manifestFailure === 'missing' || $manifestFailure === 'unknown') {
            unlink($prepared . '/virion.yml');
            if ($manifestFailure === 'unknown') {
                $this->json($prepared . '/devtools-provenance.json', []);
            }
        } elseif ($manifestFailure === 'invalid') {
            file_put_contents($prepared . '/virion.yml', str_replace(['example.library', '1.0.0'], ['RenamedPrepared', 'invalid'], $this->read($prepared . '/virion.yml')));
        }
        if ($changed) {
            file_put_contents($prepared . '/src/Shared/Virion/Greeting.php', "\n// changed\n", FILE_APPEND);
        }
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        self::assertNotNull($requirements[0]->composerPackageSha256);
        $registry = $this->load($virions, $requirements, $resolver);
        self::assertCount($changed || $renamedPrepared || $manifestFailure !== 'none' ? 0 : 1, $registry->loaded());
        if ($manifestFailure === 'none') {
            self::assertSame(VirionStatus::SKIPPED, $registry->entries()[0]->status);
        } else {
            self::assertNotNull((new VirionRequirementChecker($registry, new VirionProjectSelector()))->failure($requirements));
        }
        if ($changed) {
            self::assertStringContainsString('changed after preparation', $registry->entries()[1]->message);
        }
    }

    /** @return iterable<string, array{bool, bool, bool, string}> */
    public static function preparedState(): iterable
    {
        yield 'verified' => [false, false, false, 'none'];
        yield 'changed' => [true, false, false, 'none'];
        yield 'canonical local name' => [false, true, false, 'none'];
        yield 'renamed prepared' => [false, false, true, 'none'];
        yield 'missing prepared manifest' => [false, false, false, 'missing'];
        yield 'unreadable prepared identity' => [false, false, false, 'invalid'];
        yield 'missing prepared identity' => [false, false, false, 'unknown'];
    }

    #[DataProvider('preparedParent')]
    public function test_mixed_prepared_parent_and_local_child_use_the_same_runtime_graph(bool $preparedParent): void
    {
        [$project, $virions] = $this->project();
        $parent = $this->package($project);
        $parent['require'] = ['php' => '^8.1', 'example/child' => '^1.0'];
        $child = [
            'name' => 'example/child', 'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Example\\Child\\' => 'src/']],
            'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'Example\\Child']],
        ];
        $lock = (new ComposerVirionPlan())->readJson($project . '/composer.lock');
        $lock['packages'] = [$parent, $child];
        $this->json($project . '/composer.lock', $lock);
        $this->filesystem->ensureDirectory($project . '/vendor/example/library');
        $this->filesystem->copyTree($virions . '/Library/src', $project . '/vendor/example/library/src');
        $this->json($project . '/vendor/example/library/composer.json', $parent);
        $this->filesystem->ensureDirectory($project . '/vendor/example/child/src');
        $this->json($project . '/vendor/example/child/composer.json', $child);
        file_put_contents($project . '/vendor/example/child/src/Value.php', '<?php namespace Example\\Child; final class Value {}');
        $this->filesystem->ensureDirectory($project . '/vendor/composer');
        $this->json($project . '/vendor/composer/installed.json', ['packages' => [$parent, $child]]);
        (new ComposerVirionPreparer($this->filesystem))->prepare($project, $this->temporaryDirectory . '/prepared');
        if ($preparedParent) {
            $this->filesystem->copyTree($this->temporaryDirectory . '/prepared/example.library', $virions . '/Parent');
            $this->filesystem->removeTree($virions . '/Library');
        } else {
            $this->json($virions . '/Library/composer.json', $parent);
            file_put_contents($virions . '/Library/virion.yml', "virions: [{name: ChildLibrary, version: '1.0.0'}]\n", FILE_APPEND);
            $this->filesystem->copyTree($this->temporaryDirectory . '/prepared/example.child', $virions . '/PreparedChild');
        }
        $this->filesystem->copyTree($project . '/vendor/example/child', $virions . '/Child');
        file_put_contents($virions . '/Child/virion.yml', "name: ChildLibrary\nversion: 1.0.0\nantigen: Example\\Child\napi: 5.0.0\n");
        $resolver = $this->resolver();
        $requirements = $resolver->resolve($project, 'BuildFixture', $virions);
        $registry = $this->load($virions, $requirements, $resolver);
        self::assertCount(2, $registry->loaded());
        self::assertNotNull($registry->findByName($preparedParent ? 'ChildLibrary' : 'example.child'));
        self::assertNotNull($registry->findByName($preparedParent ? 'example.library' : 'SharedVirion'));
        self::assertNull((new VirionRequirementChecker($registry, new VirionProjectSelector()))->failure($requirements));
    }

    /** @return iterable<string, array{bool}> */
    public static function preparedParent(): iterable
    {
        yield 'prepared parent' => [true];
        yield 'prepared child with local name' => [false];
    }

    private function resolver(bool $allowLocalComposer = true, ?DebugLogger $debug = null, ?Translator $translator = null): SharedVirionConfigResolver
    {
        return new SharedVirionConfigResolver(
            new BuildConfigResolver(new YamlReader()),
            new VirionDiscovery($this->filesystem),
            new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
            $allowLocalComposer,
            $debug,
            $translator,
        );
    }

    /** @param list<\AxolotlBucketLabs\DevTools\Virion\VirionRequirement> $requirements */
    private function load(string $virions, array $requirements, SharedVirionConfigResolver $resolver): VirionRegistry
    {
        $registry = new VirionRegistry();
        $manager = new VirionManager(
            new VirionDiscovery($this->filesystem),
            new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
            new VirionClassScanner(new PhpSourceInspector()),
            new PocketMineClassLoaderRegistrar(new ThreadSafeClassLoader()),
            $registry,
            new VirionProjectSelector(),
            '5.51.1',
        );
        return $manager->discoverAndLoad($virions, $requirements, $resolver->aliases(), $resolver->skippedLocations());
    }

    /** @return array<string, mixed> */
    private function package(string $project): array
    {
        return array_values((new ComposerVirionPlan())->packages($project))[0];
    }

    /** @param array<string, mixed> $package */
    private function lockPackage(string $project, array $package): void
    {
        $lock = (new ComposerVirionPlan())->readJson($project . '/composer.lock');
        $lock['packages'] = [$package];
        $this->json($project . '/composer.lock', $lock);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        return $contents;
    }

    /** @return array{string, string} */
    private function project(): array
    {
        $project = $this->copyFixture('BuildPlugin');
        unlink($project . '/devtools.yml');
        $library = $this->copyFixture('SharedVirion', 'virions/Library');
        $root = ['name' => 'example/plugin', 'require' => ['example/library' => '^1.0']];
        $package = [
            'name' => 'example/library',
            'version' => '1.0.0',
            'require' => ['php' => '^8.1'],
            'autoload' => ['psr-4' => ['Shared\\Virion\\' => 'src/Shared/Virion/']],
            'extra' => ['virion' => ['spec' => '3.0', 'namespace-root' => 'Shared\\Virion']],
        ];
        $this->json($project . '/composer.json', $root);
        ksort($root);
        $this->json($project . '/composer.lock', [
            'content-hash' => md5(json_encode($root, JSON_THROW_ON_ERROR)),
            'packages' => [$package],
        ]);
        unset($package['version']);
        $this->json($library . '/composer.json', $package);

        return [$project, $this->temporaryDirectory . '/virions'];
    }

    /** @param array<string, mixed> $value */
    private function json(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
