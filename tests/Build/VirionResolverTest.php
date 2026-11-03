<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Build;

use NhanAZ\DevTools\Build\BuildException;
use NhanAZ\DevTools\Build\VirionResolver;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Tests\TestCase;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionProjectSelector;
use NhanAZ\DevTools\Virion\VirionRequirement;
use Phar;

final class VirionResolverTest extends TestCase
{
    public function test_invalid_folder_manifest_does_not_fall_back_to_same_name_phar(): void
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
}
