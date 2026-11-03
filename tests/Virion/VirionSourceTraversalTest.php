<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Virion;

use NhanAZ\DevTools\Support\PhpSourceInspector;
use NhanAZ\DevTools\Tests\TestCase;
use NhanAZ\DevTools\Virion\VirionClassScanner;
use NhanAZ\DevTools\Virion\VirionException;
use NhanAZ\DevTools\Virion\VirionManifest;
use NhanAZ\DevTools\Virion\VirionProject;
use NhanAZ\DevTools\Virion\VirionSourceFingerprint;

final class VirionSourceTraversalTest extends TestCase
{
    /** @dataProvider traversalProvider */
    public function test_source_links_are_rejected_before_reading_external_files(bool $fingerprint, bool $linkedRoot): void
    {
        $source = $this->temporaryDirectory . '/src';
        $outside = $this->temporaryDirectory . '/outside';
        $this->filesystem->ensureDirectory($outside);
        file_put_contents($outside . '/Invalid.php', '<?php this is not valid PHP');
        if (!$linkedRoot) {
            $this->filesystem->ensureDirectory($source);
        }
        $this->createSymbolicLink($outside, $linkedRoot ? $source : $source . '/Linked');

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('symbolic link');
        if ($fingerprint) {
            (new VirionSourceFingerprint())->hash($source);
        } else {
            $project = new VirionProject(
                $this->temporaryDirectory,
                $source,
                new VirionManifest('SourceProbe', '1.0.0', 'Probe', [], ['8.4'], [], ''),
                false,
            );
            (new VirionClassScanner(new PhpSourceInspector()))->scan($project);
        }
    }

    /** @dataProvider antigenBoundaryProvider */
    public function test_function_and_constant_equal_to_antigen_are_rejected(string $declaration): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/Virion';
        $this->filesystem->ensureDirectory($source);
        file_put_contents($source . '/Greeting.php', '<?php namespace Audit\\Virion; final class Greeting {}');
        file_put_contents($source . '/Boundary.php', "<?php\nnamespace Audit;\n{$declaration}\n");
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('Boundary', '1.0.0', 'Audit\\Virion', [], ['8.4'], [], ''),
            false,
        );

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('outside antigen');

        (new VirionClassScanner(new PhpSourceInspector()))->scan($project);
    }

    /** @return iterable<string, array{string}> */
    public static function antigenBoundaryProvider(): iterable
    {
        yield 'function' => ['function Virion(): string { return \'function\'; }'];
        yield 'constant' => ['const Virion = \'constant\';'];
    }

    public function test_named_function_inside_method_is_rejected_as_an_external_symbol(): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/Virion';
        $this->filesystem->ensureDirectory($source);
        file_put_contents($source . '/Greeting.php', '<?php namespace Audit\\Virion; final class Greeting {}');
        file_put_contents($source . '/External.php', <<<'PHP'
<?php
namespace ExternalHelpers;

new class {
    public function register(): void
    {
        function collision(): string
        {
            return 'collision';
        }
    }
};
PHP);
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('NestedFunction', '1.0.0', 'Audit\\Virion', [], ['8.4'], [], ''),
            false,
        );

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('outside antigen');

        (new VirionClassScanner(new PhpSourceInspector()))->scan($project);
    }

    public function test_autoload_shim_must_require_the_actual_class_file(): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/Shim';
        $this->filesystem->ensureDirectory($source . '/Elsewhere');
        file_put_contents($source . '/Bar.php', '<?php namespace Audit\\Shim; final class Bar {} final class Foo {}');
        file_put_contents($source . '/Elsewhere/Bar.php', '<?php namespace Audit\\Shim\\Elsewhere; final class Bar {}');
        file_put_contents($source . '/Foo.php', "<?php\nrequire_once __DIR__ . '/Elsewhere/Bar.php';\n");
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('WrongShim', '1.0.0', 'Audit\\Shim', [], ['8.4'], [], ''),
            false,
        );

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('class Audit\\Shim\\Foo is declared');

        (new VirionClassScanner(new PhpSourceInspector()))->scan($project);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function traversalProvider(): iterable
    {
        yield 'scanner linked root' => [false, true];
        yield 'scanner nested link' => [false, false];
        yield 'fingerprint linked root' => [true, true];
        yield 'fingerprint nested link' => [true, false];
    }
}
