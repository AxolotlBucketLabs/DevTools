<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\ArchiveEntry;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\PharArchiveReader;
use AxolotlBucketLabs\DevTools\Build\ZipArchiveWriter;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use PharData;
use function file_put_contents;
use const PHP_OS_FAMILY;

final class ZipArchiveWriterTest extends TestCase
{
	public function test_converts_a_phar_archive_to_zip() : void
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

	public function test_existing_zip_is_protected() : void
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

	public function test_case_conflicting_directory_components_are_rejected() : void
	{
		if (PHP_OS_FAMILY === 'Windows') {
			self::markTestSkipped('Case-conflicting directories are merged by Windows filesystem semantics.');
		}
		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('case-conflicting path component');

		(new ZipArchiveWriter($this->filesystem))->write(
			new FakeArchiveReader([
				new ArchiveEntry('src/Foo/A.php', '<?php'),
				new ArchiveEntry('src/foo/B.php', '<?php'),
			]),
			$this->temporaryDirectory . '/conflicting.zip',
		);
	}
}
