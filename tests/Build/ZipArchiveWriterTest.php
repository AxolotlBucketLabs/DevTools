<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Build;

use NhanAZ\DevTools\Build\PharArchiveReader;
use NhanAZ\DevTools\Build\ZipArchiveWriter;
use NhanAZ\DevTools\Tests\TestCase;
use PharData;

final class ZipArchiveWriterTest extends TestCase
{
    public function test_converts_a_phar_archive_to_zip(): void
    {
        $project = $this->copyFixture('BuildPlugin', 'plugins/BuildPlugin');
        $this->copyFixture('SharedVirion', 'virions/SharedVirion');
        $result = $this->builder()->build($project, $this->temporaryDirectory . '/virions', $this->temporaryDirectory . '/build');
        $zip = $this->temporaryDirectory . '/build/BuildFixture.zip';

        $count = (new ZipArchiveWriter($this->filesystem))->write(new PharArchiveReader($result->outputPath), $zip);

        self::assertGreaterThan(0, $count);
        self::assertFileExists($zip);
        $archive = new PharData($zip);
        self::assertTrue(isset($archive['plugin.yml']));
        self::assertTrue(isset($archive['src/Main.php']));
    }

    public function test_existing_zip_is_protected(): void
    {
        $zip = $this->temporaryDirectory . '/existing.zip';
        file_put_contents($zip, 'previous');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to replace existing ZIP');
        (new ZipArchiveWriter($this->filesystem))->write(
            new FakeArchiveReader([]),
            $zip,
        );
    }
}
