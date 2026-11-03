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

    public function test_function_inside_anonymous_class_constructor_closure_is_rejected_as_an_external_symbol(): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/Virion';
        $this->filesystem->ensureDirectory($source);
        file_put_contents($source . '/Greeting.php', '<?php namespace Audit\\Virion; final class Greeting {}');
        file_put_contents($source . '/External.php', <<<'PHP'
<?php
namespace ExternalHelpers;

new class((function () {
    function collision(): string
    {
        return 'collision';
    }
})()) {};
PHP);
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('ConstructorClosure', '1.0.0', 'Audit\\Virion', [], ['8.4'], [], ''),
            false,
        );

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('outside antigen');

        (new VirionClassScanner(new PhpSourceInspector()))->scan($project);
    }

    public function test_class_outside_antigen_is_rejected_during_scan(): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/Virion';
        $this->filesystem->ensureDirectory($source);
        file_put_contents($source . '/Greeting.php', '<?php namespace Audit\Virion; final class Greeting {}');
        file_put_contents($source . '/Outside.php', '<?php namespace External; final class Wrong {}');
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('OutsideClass', '1.0.0', 'Audit\Virion', [], ['8.4'], [], ''),
            false,
        );

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('class External\Wrong is outside antigen');

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

    public function test_comment_does_not_count_as_an_autoload_shim(): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/CommentShim';
        $this->filesystem->ensureDirectory($source . '/Elsewhere');
        file_put_contents($source . '/Bar.php', '<?php namespace Audit\\CommentShim; final class Bar {} final class Foo {}');
        file_put_contents($source . '/Elsewhere/Bar.php', '<?php namespace Audit\\CommentShim\\Elsewhere; final class Bar {}');
        file_put_contents($source . '/Foo.php', "<?php\n// require_once __DIR__ . '/Elsewhere/Bar.php';");
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('CommentShim', '1.0.0', 'Audit\\CommentShim', [], ['8.4'], [], ''),
            false,
        );

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('class Audit\\CommentShim\\Foo is declared');

        (new VirionClassScanner(new PhpSourceInspector()))->scan($project);
    }

    /** @dataProvider validAutoloadShimProvider */
    public function test_autoload_shim_accepts_relative_require_syntax(string $require): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/Shim';
        $this->filesystem->ensureDirectory($source);
        file_put_contents($source . '/Bar.php', '<?php namespace Audit\Shim; final class Bar {} final class Foo {}');
        file_put_contents($source . '/Foo.php', "<?php\n{$require}\n");
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('ValidShim', '1.0.0', 'Audit\Shim', [], ['8.4'], [], ''),
            false,
        );

        self::assertSame(['Audit\Shim\Bar', 'Audit\Shim\Foo'], (new VirionClassScanner(new PhpSourceInspector()))->scan($project));
    }

    public function test_grouped_function_and_constant_imports_are_not_declarations(): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/GroupedImports';
        $this->filesystem->ensureDirectory($source);
        file_put_contents($source . '/A.php', <<<'PHP'
<?php
namespace Audit\GroupedImports;

use Audit\Helpers\{function answer, const VALUE};

final class A
{
    public static function value(): int
    {
        return answer() + VALUE;
    }
}
PHP);
        file_put_contents($source . '/B.php', <<<'PHP'
<?php
namespace Audit\GroupedImports;

use Audit\Helpers\{function answer, const VALUE};

final class B
{
    public static function value(): int
    {
        return answer() + VALUE;
    }
}
PHP);
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('GroupedImports', '1.0.0', 'Audit\GroupedImports', [], ['8.4'], [], ''),
            false,
        );

        self::assertSame(['Audit\GroupedImports\A', 'Audit\GroupedImports\B'], (new VirionClassScanner(new PhpSourceInspector()))->scan($project));
    }

    /** @return iterable<string, array{string}> */
    public static function validAutoloadShimProvider(): iterable
    {
        yield 'direct relative string' => ["require_once __DIR__ . '/Bar.php';"];
        yield 'concatenated relative string' => ["require_once __DIR__ . '/' . 'Bar.php';"];
    }

    public function test_autoload_shim_rejects_duplicate_functions_across_files(): void
    {
        $source = $this->temporaryDirectory . '/src/Audit/Functions';
        $this->filesystem->ensureDirectory($source);
        file_put_contents($source . '/Greeting.php', '<?php namespace Audit\Functions; final class Greeting {}');
        file_put_contents($source . '/A.php', '<?php namespace Audit\Functions; function helper(): string { return \'a\'; }');
        file_put_contents($source . '/B.php', '<?php namespace Audit\Functions; function helper(): string { return \'b\'; }');
        $project = new VirionProject(
            $this->temporaryDirectory,
            $source,
            new VirionManifest('DuplicateFunctions', '1.0.0', 'Audit\Functions', [], ['8.4'], [], ''),
            false,
        );

        $this->expectException(VirionException::class);
        $this->expectExceptionMessage('function Audit\Functions\helper is declared more than once');

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
