<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Build;

use NhanAZ\DevTools\Build\ArchiveEntry;
use NhanAZ\DevTools\Build\ArtifactInspector;
use NhanAZ\DevTools\Build\BuildException;
use NhanAZ\DevTools\Build\PharArchiveReader;
use NhanAZ\DevTools\Build\PharExtractor;
use NhanAZ\DevTools\Tests\TestCase;

final class PharExtractorTest extends TestCase
{
    public function test_built_phar_can_be_safely_extracted(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $result = $this->builder()->build($project, $virions, $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build');
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';

        $count = (new PharExtractor($this->filesystem))->extract(new PharArchiveReader($result->outputPath), $destination);

        self::assertGreaterThan(2, $count);
        self::assertFileExists($destination . DIRECTORY_SEPARATOR . 'plugin.yml');
        self::assertSame('resource included', trim((string) file_get_contents($destination . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'message.txt')));
    }

    public function test_replacing_a_phar_then_reading_it_again_uses_the_new_archive(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
        $virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
        $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $output = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build';
        $builder = $this->builder();
        $extractor = new PharExtractor($this->filesystem);
        $first = $builder->build($project, $virions, $output);
        $extractor->extract(new PharArchiveReader($first->outputPath), $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'first');

        $mainPath = $project . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php';
        $original = file_get_contents($mainPath);
        self::assertIsString($original);
        $updated = $original . "\n/* deliberately longer replacement */\n";
        file_put_contents($mainPath, $updated);
        $pluginManifestPath = $project . DIRECTORY_SEPARATOR . 'plugin.yml';
        $pluginManifest = file_get_contents($pluginManifestPath);
        self::assertIsString($pluginManifest);
        file_put_contents($pluginManifestPath, str_replace('version: 1.0.0', 'version: 2.0.0', $pluginManifest));

        $second = $builder->build($project, $virions, $output, true);
        $extractor->extract(new PharArchiveReader($second->outputPath), $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'second');

        $firstMain = file_get_contents($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'first' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php');
        $secondMain = file_get_contents($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'second' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php');
        self::assertIsString($firstMain);
        self::assertIsString($secondMain);
        self::assertStringNotContainsString('deliberately longer replacement', $firstMain);
        self::assertStringContainsString('deliberately longer replacement', $secondMain);
        [$reader] = $this->validationServices();
        $inspection = (new ArtifactInspector($reader))->inspect($second->outputPath);
        $plugin = $inspection['plugin'] ?? null;
        self::assertIsArray($plugin);
        self::assertSame('2.0.0', $plugin['version'] ?? null);
    }

    /** @dataProvider unsafePathProvider */
    public function test_malicious_paths_are_rejected(string $path): void
    {
        $extractor = new PharExtractor($this->filesystem);
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('unsafe archive entry');

        $extractor->extract(new FakeArchiveReader([new ArchiveEntry($path, 'malicious')]), $destination);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePathProvider(): iterable
    {
        yield 'parent traversal' => ['../outside.php'];
        yield 'absolute unix path' => ['/outside.php'];
        yield 'windows drive path' => ['C:\\outside.php'];
        yield 'windows drive-relative path' => ['C:outside.php'];
        yield 'null byte' => ["safe.php\0outside.php"];
        yield 'control character' => ["unsafe\x01.php"];
        yield 'unit separator' => ["unsafe\x1f.php"];
        yield 'newline' => ["unsafe\n.php"];
        yield 'alternate data stream' => ['safe.php:stream'];
        yield 'trailing dot' => ['safe./file.php'];
        yield 'reserved device' => ['CON/file.php'];
    }

    public function test_nonportable_path_is_rejected_before_changing_existing_output(): void
    {
        $destination = $this->temporaryDirectory . '/extracted';
        $this->filesystem->ensureDirectory($destination);
        file_put_contents($destination . '/plugin.yml', 'original');

        try {
            (new PharExtractor($this->filesystem))->extract(
                new FakeArchiveReader([
                    new ArchiveEntry('plugin.yml', 'replacement'),
                    new ArchiveEntry("unsafe\n.php", 'invalid'),
                ]),
                $destination,
                true,
            );
            self::fail('Expected portable path validation.');
        } catch (BuildException $error) {
            self::assertStringContainsString('unsafe archive entry', $error->getMessage());
        }
        self::assertSame('original', file_get_contents($destination . '/plugin.yml'));
    }

    public function test_existing_file_is_not_overwritten_without_permission(): void
    {
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';
        $this->filesystem->ensureDirectory($destination);
        file_put_contents($destination . DIRECTORY_SEPARATOR . 'plugin.yml', 'original');

        try {
            (new PharExtractor($this->filesystem))->extract(
                new FakeArchiveReader([new ArchiveEntry('plugin.yml', 'replacement')]),
                $destination,
            );
            self::fail('Expected overwrite protection.');
        } catch (BuildException $error) {
            self::assertStringContainsString('Refusing to overwrite', $error->getMessage());
        }
        self::assertSame('original', file_get_contents($destination . DIRECTORY_SEPARATOR . 'plugin.yml'));
    }

    public function test_file_directory_prefix_conflict_is_rejected_before_writing(): void
    {
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';

        try {
            (new PharExtractor($this->filesystem))->extract(
                new FakeArchiveReader([
                    new ArchiveEntry('first.php', 'first'),
                    new ArchiveEntry('path', 'file'),
                    new ArchiveEntry('path/child.php', 'child'),
                ]),
                $destination,
            );
            self::fail('Expected prefix conflict protection.');
        } catch (BuildException $error) {
            self::assertStringContainsString('prefix conflict', $error->getMessage());
        }
        self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'first.php');
    }

    public function test_case_conflicting_archive_paths_are_rejected_before_writing(): void
    {
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';

        try {
            (new PharExtractor($this->filesystem))->extract(
                new FakeArchiveReader([
                    new ArchiveEntry('src/Foo/A.php', 'upper'),
                    new ArchiveEntry('src/foo/B.php', 'lower'),
                ]),
                $destination,
            );
            self::fail('Expected case-conflicting archive paths to be rejected.');
        } catch (BuildException $error) {
            self::assertStringContainsString('case-conflicting path component', $error->getMessage());
        }
        self::assertFileDoesNotExist($destination);
    }

    public function test_destination_symlink_is_rejected(): void
    {
        $outside = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'outside';
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'linked';
        $this->filesystem->ensureDirectory($outside);
        set_error_handler(static fn(): bool => true);
        try {
            $linked = symlink($outside, $destination);
        } finally {
            restore_error_handler();
        }
        if (!$linked) {
            self::markTestSkipped('Creating directory symlinks is not permitted on this system.');
        }

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('symbolic-link extraction destination');

        (new PharExtractor($this->filesystem))->extract(
            new FakeArchiveReader([new ArchiveEntry('plugin.yml', 'safe')]),
            $destination,
        );
    }

    public function test_overwrite_is_installed_transactionally_and_preserves_other_files(): void
    {
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';
        $this->filesystem->ensureDirectory($destination);
        file_put_contents($destination . DIRECTORY_SEPARATOR . 'plugin.yml', 'old');
        file_put_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');

        $count = (new PharExtractor($this->filesystem))->extract(
            new FakeArchiveReader([
                new ArchiveEntry('plugin.yml', 'new'),
                new ArchiveEntry('src/Main.php', '<?php'),
            ]),
            $destination,
            true,
        );

        self::assertSame(2, $count);
        self::assertSame('new', file_get_contents($destination . DIRECTORY_SEPARATOR . 'plugin.yml'));
        self::assertSame('keep', file_get_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt'));
        self::assertFileExists($destination . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Main.php');
    }

    public function test_existing_permissions_survive_extraction(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission bits are not reliable on Windows.');
        }
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'private';
        $nested = $destination . DIRECTORY_SEPARATOR . 'nested';
        $this->filesystem->ensureDirectory($nested);
        file_put_contents($nested . DIRECTORY_SEPARATOR . 'credentials.json', 'secret');
        file_put_contents($destination . DIRECTORY_SEPARATOR . 'script.sh', '#!/bin/sh');
        chmod($destination, 0700);
        chmod($nested, 0700);
        chmod($nested . DIRECTORY_SEPARATOR . 'credentials.json', 0600);
        chmod($destination . DIRECTORY_SEPARATOR . 'script.sh', 0755);

        (new PharExtractor($this->filesystem))->extract(
            new FakeArchiveReader([new ArchiveEntry('plugin.yml', 'name: FixturePlugin')]),
            $destination,
        );

        self::assertSame(0700, fileperms($destination) & 0777);
        self::assertSame(0700, fileperms($nested) & 0777);
        self::assertSame(0600, fileperms($nested . DIRECTORY_SEPARATOR . 'credentials.json') & 0777);
        self::assertSame(0755, fileperms($destination . DIRECTORY_SEPARATOR . 'script.sh') & 0777);
    }

    public function test_case_conflicting_directory_components_are_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Case-conflicting directories are merged by Windows filesystem semantics.');
        }
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('case-conflicting path component');

        (new PharExtractor($this->filesystem))->extract(
            new FakeArchiveReader([
                new ArchiveEntry('src/Foo/A.php', '<?php'),
                new ArchiveEntry('src/foo/B.php', '<?php'),
            ]),
            $destination,
        );
    }

    public function test_case_conflict_with_existing_destination_is_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Case-conflicting directories are merged by Windows filesystem semantics.');
        }
        $destination = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'extracted';
        $this->filesystem->ensureDirectory($destination . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Foo');
        file_put_contents($destination . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Foo' . DIRECTORY_SEPARATOR . 'A.php', '<?php');

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('case-conflicting path component');

        (new PharExtractor($this->filesystem))->extract(
            new FakeArchiveReader([new ArchiveEntry('src/foo/B.php', '<?php')]),
            $destination,
        );
    }
}
