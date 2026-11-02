<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Build;

use NhanAZ\DevTools\Build\ArtifactInspector;
use NhanAZ\DevTools\Build\ProjectRoleDetector;
use NhanAZ\DevTools\Build\VirionBuilder;
use NhanAZ\DevTools\Support\PhpSourceInspector;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Tests\TestCase;
use NhanAZ\DevTools\Virion\VirionClassScanner;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use Phar;

final class VirionBuilderTest extends TestCase
{
    public function test_builds_and_inspects_a_virion_phar(): void
    {
        $project = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $yaml = new YamlReader();
        $manifestReader = new VirionManifestReader($yaml);
        $result = (new VirionBuilder(
            $this->filesystem,
            $manifestReader,
            new VirionProjectFactory($manifestReader),
            new VirionClassScanner(new PhpSourceInspector()),
        ))->build($project, $this->temporaryDirectory . '/build');

        self::assertFileExists($result->outputPath);
        $phar = new Phar($result->outputPath);
        self::assertTrue(isset($phar['virion.yml']));
        self::assertTrue(isset($phar['src/Shared/Virion/Greeting.php']));
        self::assertFalse(isset($phar['plugin.yml']));

        $inspected = (new ArtifactInspector(
            $this->validationServices()[0],
            $manifestReader,
        ))->inspect($result->outputPath);
        self::assertSame('virion', $inspected['type']);
        self::assertIsArray($inspected['virion']);
        self::assertSame('SharedVirion', $inspected['virion']['name']);
        self::assertSame('1.0.0', $inspected['virion']['version']);
    }

    public function test_project_role_detector_identifies_dual_role_projects(): void
    {
        $project = $this->copyFixture('ValidPlugin', 'dual');
        file_put_contents($project . '/virion.yml', "name: DualLibrary\nversion: 1.0.0\nantigen: Fixture\\\\Plugin\napi: 5.0.0\n");

        self::assertSame('dual', (new ProjectRoleDetector())->detect($project)->value);
    }
}
