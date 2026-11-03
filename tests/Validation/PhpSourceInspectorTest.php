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
}
