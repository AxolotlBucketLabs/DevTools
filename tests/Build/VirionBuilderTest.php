<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\ArtifactInspector;
use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\ProjectRoleDetector;
use AxolotlBucketLabs\DevTools\Build\VirionBuilder;
use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionSourceFingerprint;
use Phar;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash_file;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class VirionBuilderTest extends TestCase
{
	public function test_builds_and_inspects_a_virion_phar() : void
	{
		$project = $this->copyFixture('SharedVirion', 'virions/SharedVirion');
		$this->filesystem->removeTree($project . '/resources');
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

	public function test_built_phar_with_an_autoload_shim_can_be_scanned_again() : void
	{
		$project = $this->copyFixture('SharedVirion', 'virions/ShimVirion');
		$source = $project . '/src/Shared/Virion';
		$this->filesystem->removeTree($source);
		$this->filesystem->ensureDirectory($source);
		file_put_contents($source . '/Bar.php', '<?php namespace Shared\Virion; final class Bar {} final class Foo {}');
		file_put_contents($source . '/Foo.php', "<?php\nrequire_once __DIR__ . '/' . 'Bar.php';\n");

		$yaml = new YamlReader();
		$manifestReader = new VirionManifestReader($yaml);
		$scanner = new VirionClassScanner(new PhpSourceInspector());
		$result = (new VirionBuilder(
			$this->filesystem,
			$manifestReader,
			new VirionProjectFactory($manifestReader),
			$scanner,
		))->build($project, $this->temporaryDirectory . '/build');

		$pharProject = (new VirionProjectFactory($manifestReader))->open($result->outputPath);

		self::assertSame(['Shared\Virion\Bar', 'Shared\Virion\Foo'], $scanner->scan($pharProject));
	}

	public function test_existing_output_requires_explicit_overwrite() : void
	{
		$project = $this->copyFixture('SharedVirion');
		$output = $this->temporaryDirectory . '/build';
		$builder = $this->virionBuilder();
		$first = $builder->build($project, $output);
		$hash = hash_file('sha256', $first->outputPath);

		try {
			$builder->build($project, $output);
			self::fail('Expected existing output protection.');
		} catch (BuildException $error) {
			self::assertSame("Refusing to replace existing build {$first->outputPath}. Run the build again with overwrite explicitly enabled.", $error->getMessage());
		}
		self::assertSame($hash, hash_file('sha256', $first->outputPath));
	}

	public function test_explicit_overwrite_installs_new_virion_and_cleans_backups() : void
	{
		$project = $this->copyFixture('SharedVirion');
		$output = $this->temporaryDirectory . '/build';
		$builder = $this->virionBuilder();
		$first = $builder->build($project, $output);
		$hash = hash_file('sha256', $first->outputPath);
		file_put_contents($project . '/LICENSE', 'updated notice');

		$second = $builder->build($project, $output, true);

		self::assertNotSame($hash, hash_file('sha256', $second->outputPath));
		self::assertSame('updated notice', file_get_contents('phar://' . $second->outputPath . '/LICENSE'));
		self::assertSame([], glob($output . '/*.backup-*'));
		self::assertSame([], glob($output . '/.devtools-virion-stage-*'));
		self::assertSame([], glob($output . '/.*.tmp.phar'));
	}

	public function test_staging_failure_preserves_existing_virion_and_cleans_temporary_files() : void
	{
		$project = $this->copyFixture('SharedVirion');
		$output = $this->temporaryDirectory . '/build';
		$builder = $this->virionBuilder();
		$first = $builder->build($project, $output);
		$hash = hash_file('sha256', $first->outputPath);
		$this->filesystem->ensureDirectory($project . '/resources');
		file_put_contents($project . '/resources/unsupported.txt', 'root resource');

		try {
			$builder->build($project, $output, true);
			self::fail('Expected root resources to be rejected.');
		} catch (BuildException $error) {
			self::assertStringContainsString('root resources/', $error->getMessage());
		}
		self::assertSame($hash, hash_file('sha256', $first->outputPath));
		self::assertSame([], glob($output . '/*.backup-*'));
		self::assertSame([], glob($output . '/.devtools-virion-stage-*'));
		self::assertSame([], glob($output . '/.*.tmp.phar'));
	}

	public function test_provenance_failure_is_wrapped_without_changing_the_diagnostic() : void
	{
		$project = $this->copyFixture('SharedVirion');
		$output = $this->temporaryDirectory . '/build';
		file_put_contents($project . '/devtools-provenance.json', '{}');

		try {
			$this->virionBuilder()->build($project, $output);
			self::fail('Expected unverified manifest metadata to be rejected.');
		} catch (BuildException $error) {
			self::assertSame('Prepared manifest for SharedVirion changed or lacks verification metadata. Run prepare into a new directory from the reviewed installed package.', $error->getMessage());
			self::assertInstanceOf(VirionException::class, $error->getPrevious());
		}
		self::assertFileDoesNotExist($output . '/SharedVirion.phar');
		self::assertSame([], glob($output . '/.devtools-virion-stage-*'));
		self::assertSame([], glob($output . '/.*.tmp.phar'));
	}

	public function test_prepared_content_without_package_pin_preserves_provenance_bytes() : void
	{
		$root = $this->copyFixture('SharedVirion');
		$project = (new VirionProjectFactory(new VirionManifestReader(new YamlReader())))->open($root);
		$metadata = json_encode([
			'manifestSha256' => hash_file('sha256', $project->manifest->path),
			'sourceSha256' => (new VirionSourceFingerprint())->hash($project->sourceRoot),
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
		file_put_contents($root . '/devtools-provenance.json', $metadata);

		$result = $this->virionBuilder()->build($root, $this->temporaryDirectory . '/build');

		self::assertSame($metadata, file_get_contents('phar://' . $result->outputPath . '/devtools-provenance.json'));
	}

	public function test_project_role_detector_identifies_dual_role_projects() : void
	{
		$project = $this->copyFixture('ValidPlugin', 'dual');
		file_put_contents($project . '/virion.yml', "name: DualLibrary\nversion: 1.0.0\nantigen: Fixture\\\\Plugin\napi: 5.0.0\n");

		self::assertSame('dual', (new ProjectRoleDetector())->detect($project)->value);
	}

	public function test_output_inside_resources_is_rejected() : void
	{
		$project = $this->copyFixture('SharedVirion', 'virions/SharedVirion');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('source or resources');
		(new VirionBuilder(
			$this->filesystem,
			new VirionManifestReader(new YamlReader()),
			new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
			new VirionClassScanner(new PhpSourceInspector()),
		))->build($project, $project . '/resources/dist');
	}

	public function test_root_resources_are_rejected_for_a_standalone_virion() : void
	{
		$project = $this->copyFixture('SharedVirion', 'virions/RootResources');
		$this->filesystem->ensureDirectory($project . '/resources');
		file_put_contents($project . '/resources/default.txt', 'unsupported root resource');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('root resources');
		(new VirionBuilder(
			$this->filesystem,
			new VirionManifestReader(new YamlReader()),
			new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
			new VirionClassScanner(new PhpSourceInspector()),
		))->build($project, $this->temporaryDirectory . '/build');
	}

	public function test_class_equal_to_antigen_is_rejected_consistently() : void
	{
		$project = $this->copyFixture('SharedVirion', 'virions/RootClass');
		file_put_contents(
			$project . '/virion.yml',
			"name: RootClass\nversion: 1.0.0\nantigen: Shared\\Virion\\Greeting\napi: 5.0.0\n",
		);

		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('must be below antigen');
		(new VirionBuilder(
			$this->filesystem,
			new VirionManifestReader(new YamlReader()),
			new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
			new VirionClassScanner(new PhpSourceInspector()),
		))->build($project, $this->temporaryDirectory . '/build');
	}

	private function virionBuilder() : VirionBuilder
	{
		$reader = new VirionManifestReader(new YamlReader());
		return new VirionBuilder(
			$this->filesystem,
			$reader,
			new VirionProjectFactory($reader),
			new VirionClassScanner(new PhpSourceInspector()),
		);
	}
}
