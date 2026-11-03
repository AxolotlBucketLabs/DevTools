<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Validation;

use NhanAZ\DevTools\Support\PhpSourceInspector;
use NhanAZ\DevTools\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PhpSourceInspectorTest extends TestCase
{
    #[DataProvider('anonymous_classes')]
    public function test_anonymous_classes_do_not_declare_their_parent_or_interfaces(string $expression): void
    {
        $path = $this->temporaryDirectory . '/Classes.php';
        file_put_contents($path, '<?php namespace Fixture; class Actual {} ' . $expression . ';');

        self::assertSame(['Fixture\\Actual'], (new PhpSourceInspector())->inspect($path)->classes);
    }

    /** @return iterable<string, array{string}> */
    public static function anonymous_classes(): iterable
    {
        yield 'plain' => ['new class extends ParentClass {}'];
        yield 'attributed parent' => ['new #[Example] class extends ParentClass {}'];
        yield 'attributed interface' => ['new #[Example] class implements Contract {}'];
        yield 'readonly parent' => ['new readonly class extends ParentClass {}'];
        yield 'attributed readonly' => ['new #[Example] readonly class implements Contract {}'];
    }

    public function test_named_declarations_and_namespace_blocks_are_preserved(): void
    {
        $path = $this->temporaryDirectory . '/Classes.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Fixture {
    #[Example] readonly class /* comment */ Actual {}
    interface Contract {}
    trait Behavior {}
    enum Choice { case One; }
    $name = Actual::class;
}
namespace {
    class GlobalClass {}
}
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);
        self::assertSame(['Fixture\\Actual', 'Fixture\\Contract', 'Fixture\\Behavior', 'Fixture\\Choice', 'GlobalClass'], $symbols->classes);
        self::assertSame(['Fixture', ''], $symbols->namespaces);
    }

    public function test_methods_inside_anonymous_classes_are_not_namespace_functions(): void
    {
        $path = $this->temporaryDirectory . '/AnonymousClass.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Fixture\Virion;

new class {
    public function method(): void {}
};
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame([], $symbols->functions);
    }

    public function test_reference_return_functions_are_detected(): void
    {
        $path = $this->temporaryDirectory . '/ReferenceFunction.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Fixture\Virion;

function &value(): string
{
    static $value = 'shared';
    return $value;
}
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame(['Fixture\Virion\value'], $symbols->functions);
    }

    public function test_interpolated_string_does_not_end_a_class_scope(): void
    {
        $path = $this->temporaryDirectory . '/InterpolatedString.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Fixture\Virion;

final class Value
{
    public function first(string $value): string
    {
        return "{$value}";
    }

    public function second(): string
    {
        return 'second';
    }
}
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame([], $symbols->functions);
    }

    public function test_named_function_inside_method_is_reported_as_a_namespace_function(): void
    {
        $path = $this->temporaryDirectory . '/NestedFunction.php';
        file_put_contents($path, <<<'PHP'
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

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame(['ExternalHelpers\collision'], $symbols->functions);
    }

    public function test_function_guard_is_recorded_as_conditional(): void
    {
        $path = $this->temporaryDirectory . '/GuardedFunction.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Fixture\Virion;

if (!function_exists('Fixture\\Virion\\helper')) {
    function helper(): string
    {
        return 'guarded';
    }

    function other(): string
    {
        return 'unguarded';
    }
}
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame(['Fixture\Virion\helper', 'Fixture\Virion\other'], $symbols->functions);
        self::assertSame(['Fixture\Virion\other'], $symbols->unconditionalFunctions);
    }

    public function test_function_exists_for_another_name_does_not_guard_the_declared_function(): void
    {
        $path = $this->temporaryDirectory . '/WrongGuard.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Fixture\Virion;

if (function_exists('str_contains')) {
    function helper(): string
    {
        return 'unguarded';
    }
}
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame(['Fixture\Virion\helper'], $symbols->functions);
        self::assertSame(['Fixture\Virion\helper'], $symbols->unconditionalFunctions);
    }

    public function test_closure_in_anonymous_class_argument_does_not_hide_namespace_function(): void
    {
        $path = $this->temporaryDirectory . '/ConstructorClosure.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace ExternalHelpers;

new class((function () {
    function collision(): string
    {
        return 'collision';
    }
})()) {};
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame(['ExternalHelpers\collision'], $symbols->functions);
    }

    public function test_nested_anonymous_class_in_constructor_argument_keeps_outer_class_scope(): void
    {
        $path = $this->temporaryDirectory . '/NestedAnonymousClass.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace ExternalHelpers;

function factory(): object
{
    return new class(new class {}) {
        public function __construct(public object $dependency) {}

        public function value(): string
        {
            return get_debug_type($this->dependency);
        }
    };
}
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame(['ExternalHelpers\factory'], $symbols->functions);
    }

    public function test_single_quoted_function_guard_preserves_namespace_separators(): void
    {
        $path = $this->temporaryDirectory . '/SingleQuotedGuard.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Fixture\Virion;

if (!function_exists('Fixture\Virion\helper')) {
    function helper(): string
    {
        return 'guarded';
    }
}
PHP);

        $symbols = (new PhpSourceInspector())->inspect($path);

        self::assertSame(['Fixture\Virion\helper'], $symbols->functions);
        self::assertSame([], $symbols->unconditionalFunctions);
    }
}
