<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\VirionResolver;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use Phar;
use function file_put_contents;
use function is_dir;

final class VirionResolverTest extends TestCase
{
	public function test_invalid_folder_manifest_does_not_fall_back_to_same_name_phar() : void
	{
		$directory = $this->temporaryDirectory . '/virions';
		$folder = $directory . '/FallbackProbe';
		$this->filesystem->ensureDirectory($folder . '/src/Fallback/Probe');
		file_put_contents(
			$folder . '/virion.yml',
			"name: FallbackProbe\nversion: INVALID\nantigen: Fallback\\Probe\napi: 5.0.0\n",
		);
		file_put_contents($folder . '/src/Fallback/Probe/Value.php', "<?php\nnamespace Fallback\\Probe; final class Value {}\n");

		$phar = new Phar($directory . '/FallbackProbe.phar');
		$phar->startBuffering();
		$phar->addFromString('virion.yml', "name: FallbackProbe\nversion: 1.0.0\nantigen: Fallback\\Probe\napi: 5.0.0\n");
		$phar->addFromString('src/Fallback/Probe/Value.php', "<?php\nnamespace Fallback\\Probe; final class Value {}\n");
		$phar->setStub("<?php __HALT_COMPILER();\n");
		$phar->setSignatureAlgorithm(Phar::SHA256);
		$phar->stopBuffering();
		unset($phar);

		$yaml = new YamlReader();
		$manifestReader = new VirionManifestReader($yaml);
		$resolver = new VirionResolver(
			new VirionDiscovery($this->filesystem),
			new VirionProjectFactory($manifestReader),
			new VirionProjectSelector(),
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('no valid virion');

		$resolver->resolve([new VirionRequirement('FallbackProbe', '*', 'test')], $directory);
	}

	public function test_phpstan_resolution_can_use_the_original_phar_without_a_temporary_snapshot() : void
	{
		$directory = $this->temporaryDirectory . '/virions';
		$source = $this->copyFixture('SharedVirion', 'source');
		$this->filesystem->ensureDirectory($directory);
		$pharPath = $directory . '/SharedVirion.phar';
		$phar = new Phar($pharPath);
		$phar->startBuffering();
		$phar->buildFromDirectory($source);
		$phar->setStub("<?php __HALT_COMPILER();\n");
		$phar->setSignatureAlgorithm(Phar::SHA256);
		$phar->stopBuffering();
		unset($phar);

		$yaml = new YamlReader();
		$resolver = new VirionResolver(
			new VirionDiscovery($this->filesystem),
			new VirionProjectFactory(new VirionManifestReader($yaml)),
			new VirionProjectSelector(),
		);

		$projects = $resolver->resolve([new VirionRequirement('SharedVirion', '*', 'phpstan-test')], $directory, false);
		self::assertCount(1, $projects);
		$sourceRoot = $projects[0]->sourceRoot;
		unset($projects);

		self::assertTrue(is_dir($sourceRoot), $sourceRoot);
		self::assertFileExists($pharPath);
	}
}
