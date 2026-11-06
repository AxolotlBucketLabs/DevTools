<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\FilesystemException;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Tests\TestCase;

final class FilesystemTest extends TestCase
{
    public function test_regular_paths_are_not_treated_as_links(): void
    {
        $source = $this->copyFixture('ValidPlugin');
        foreach ([$source, $source . '/', $source . '/.', $source . '/..', $source . '/src/Main.php', $source . '/missing', '.', '..', dirname($source)] as $path) {
            self::assertFalse(Filesystem::isLink($path), $path);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertFalse(Filesystem::isLink(strtoupper($source)));
            self::assertFalse(Filesystem::isLink(substr($source, 0, 3)));
        }
    }

    public function test_copy_filter_and_recursive_removal_preserve_unrelated_files(): void
    {
        $source = $this->copyFixture('ValidPlugin');
        $destination = $this->temporaryDirectory . '/copy';
        $this->filesystem->copyTree($source, $destination, static fn(string $path): bool => str_ends_with($path, '.php'));
        self::assertFileExists($destination . '/src/Main.php');
        self::assertFileDoesNotExist($destination . '/plugin.yml');
        $this->filesystem->removeTree($destination);
        self::assertDirectoryDoesNotExist($destination);
        self::assertFileExists($source . '/src/Main.php');
    }

    public function test_links_are_rejected_even_if_excluded_and_removal_preserves_target(): void
    {
        $source = $this->temporaryDirectory . '/source';
        $target = $this->copyFixture('ValidPlugin');
        $this->filesystem->ensureDirectory($source);
        $link = $source . '/linked';
        $this->createSymbolicLink($target, $link);

        self::assertTrue(Filesystem::isLink($link));
        try {
            $this->filesystem->copyTree($source, $this->temporaryDirectory . '/copy', static fn(string $path): bool => false);
            self::fail('Excluded links must not be traversed.');
        } catch (FilesystemException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        $this->filesystem->removeTree($source);
        self::assertDirectoryDoesNotExist($source);
        self::assertFileExists($target . '/src/Main.php');
    }

    public function test_portable_paths_reject_case_conflicting_components(): void
    {
        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('case-conflicting path component');

        Path::assertPortablePaths([
            'src/Foo/A.php',
            'src/foo/B.php',
        ]);
    }
}
