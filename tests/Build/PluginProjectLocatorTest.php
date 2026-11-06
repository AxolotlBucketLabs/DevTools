<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\PluginProjectLocator;
use AxolotlBucketLabs\DevTools\Loader\FolderPluginDiscovery;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Validation\PluginManifestReader;

final class PluginProjectLocatorTest extends TestCase
{
    public function test_duplicate_manifest_names_require_explicit_project_selection(): void
    {
        $first = $this->copyFixture('ValidPlugin', 'plugins/A');
        $second = $this->copyFixture('ValidPlugin', 'plugins/B');
        $plugins = $this->temporaryDirectory . '/plugins';
        self::assertSame(realpath($second), $this->locator()->locate($second, $plugins));
        try {
            $this->locator()->locate('FixturePlugin', $plugins);
            self::fail('Expected ambiguous project name.');
        } catch (BuildException $error) {
            self::assertStringContainsString('ambiguous', $error->getMessage());
            self::assertStringContainsString(str_replace('\\', '/', $first), $error->getMessage());
            self::assertStringContainsString(str_replace('\\', '/', $second), $error->getMessage());
        }
    }

    public function test_direct_path_must_be_an_immediate_child_of_plugins(): void
    {
        $plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
        $outside = $this->copyFixture('ValidPlugin', 'outside/ValidPlugin');
        $this->filesystem->ensureDirectory($plugins);

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('outside the immediate plugins directory');

        $this->locator()->locate($outside, $plugins);
    }

    public function test_unicode_and_space_path_inside_plugins_is_located(): void
    {
        $plugins = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'plugins';
        $project = $this->copyFixture('ValidPlugin', 'plugins/Plugin thử nghiệm');

        self::assertSame(realpath($project), $this->locator()->locate($project, $plugins));
    }

    private function locator(): PluginProjectLocator
    {
        return new PluginProjectLocator(
            new FolderPluginDiscovery(),
            new PluginManifestReader(new YamlReader()),
        );
    }
}
