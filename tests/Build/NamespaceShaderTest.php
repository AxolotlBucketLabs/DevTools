<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\NamespaceShader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use function class_exists;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function substr_count;
use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

final class NamespaceShaderTest extends TestCase
{
	public function test_static_php_name_forms_are_shaded_by_ast() : void
	{
		$source = <<<'PHP'
<?php
namespace Fixture;
use Shared\Virion\Greeting;
use function Shared\Virion\helper;
use const Shared\Virion\VALUE;
#[\Shared\Virion\Marker]
final class Example extends \Shared\Virion\BaseType {
    use \Shared\Virion\Helpful;
    public function value(\Shared\Virion\Contract $value): Greeting {
        return \Shared\Virion\Greeting::make($value);
    }
}
PHP;
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Example.php', $source);

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);

		$result = file_get_contents($directory . DIRECTORY_SEPARATOR . 'Example.php');
		self::assertIsString($result);
		self::assertStringNotContainsString('Shared\Virion', $result);
		self::assertGreaterThanOrEqual(8, substr_count($result, 'Fixture\Shade\Shared'));
	}

	public function test_isolated_parser_does_not_mix_with_a_preloaded_global_parser_class() : void
	{
		$legacy = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'legacy-parser.php';
		file_put_contents($legacy, <<<'PHP'
<?php
namespace PhpParser\Node;

final class Name
{
}
PHP);
		$source = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'source';
		$this->filesystem->ensureDirectory($source);
		file_put_contents($source . DIRECTORY_SEPARATOR . 'Example.php', "<?php\nnamespace Fixture;\nfinal class Example {}\n");
		$script = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'parser-isolation.php';
		file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';
require $argv[2];
(new \AxolotlBucketLabs\DevTools\Build\NamespaceShader())->shadeDirectory($argv[3], ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
echo "ok\n";
PHP);

		$process = proc_open(
			[PHP_BINARY, $script, dirname(__DIR__, 2), $legacy, $source],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			dirname(__DIR__, 2),
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		self::assertSame(0, proc_close($process), (string) $stderr);
		self::assertSame("ok\n", $stdout);
	}

	public function test_dynamic_class_string_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Unsafe.php', "<?php\n\$class = 'Shared\\\\Virion\\\\Greeting';\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('string literal');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	/** @dataProvider hiddenDynamicReferenceProvider */
	public function test_aliased_and_named_dynamic_class_references_are_rejected(string $source, bool $shade) : void
	{
		$directory = $this->temporaryDirectory . '/src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . '/Unsafe.php', "<?php\nnamespace Fixture;\n" . $source);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic');

		$shader = new NamespaceShader();
		if ($shade) {
			$shader->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
		} else {
			$shader->assertNoNamespaceReferences($directory, ['Shared\\Virion']);
		}
	}

	/** @return iterable<string, array{string, bool}> */
	public static function hiddenDynamicReferenceProvider() : iterable
	{
		$sources = [
			'aliased function' => 'use function class_exists as exists; exists($className);',
			'aliased reflection' => 'use ReflectionClass as ClassInfo; new ClassInfo($className);',
			'named target' => 'is_subclass_of(class: $className, object_or_class: \stdClass::class);',
			'unpacked arguments' => 'is_subclass_of(...$arguments);',
		];
		foreach ($sources as $name => $source) {
			yield $name . ' shading' => [$source, true];
			yield $name . ' standalone' => [$source, false];
		}
	}

	public function test_named_static_class_arguments_are_allowed() : void
	{
		$directory = $this->temporaryDirectory . '/src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . '/References.php';
		file_put_contents($path, <<<'PHP'
<?php
namespace Fixture;
class_exists(autoload: false, class: \Shared\Virion\Greeting::class);
is_subclass_of(class: \Shared\Virion\Greeting::class, object_or_class: new \stdClass());
PHP);
		$shader = new NamespaceShader();

		$shader->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertSame(2, substr_count($result, '\\Fixture\\Shade\\Shared\\Greeting::class'));
	}

	public function test_dynamic_is_a_target_type_is_rejected_even_with_default_non_autoloading_mode() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Matcher.php';
		file_put_contents($path, <<<'PHP'
<?php
namespace Fixture;

use function is_a;

final class Matcher
{
    public function matches(object $throwable, string $class): bool
    {
        return is_a($throwable, $class);
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class-name argument to is_a()');

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	/** @dataProvider autoloading_object_or_class_provider */
	public function test_autoloading_object_or_class_arguments_are_rejected(string $source) : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$contents = "<?php\nnamespace Fixture;\nfinal class Matcher { public function matches(string " . '$class' . "): bool { {$source} } }\n";
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Matcher.php', $contents);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class-name argument');
		(new NamespaceShader())->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
	}

	public function test_serialized_class_reference_is_rejected_during_shading() : void
	{
		$directory = $this->temporaryDirectory . '/src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . '/Serialized.php', <<<'PHP'
<?php
namespace Fixture;

final class Serialized
{
    public static function restore(): object
    {
        return unserialize('O:22:"Shared\Virion\Greeting":0:{}');
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('serialized class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_serialized_class_reference_is_rejected_in_standalone_validation() : void
	{
		$directory = $this->temporaryDirectory . '/src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . '/Serialized.php', <<<'PHP'
<?php
namespace Fixture;

final class Serialized
{
    public static function restore(): object
    {
        return unserialize('O:22:"Shared\Virion\Greeting":0:{}');
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('development-only namespace');

		(new NamespaceShader())->assertNoNamespaceReferences($directory, ['Shared\Virion']);
	}

	public function test_serialized_enum_reference_is_rejected_during_shading() : void
	{
		$directory = $this->temporaryDirectory . '/src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . '/Serialized.php', <<<'PHP'
<?php
namespace Fixture;

final class Serialized
{
    public static function restore(): mixed
    {
        return unserialize('a:1:{i:0;E:25:"Shared\Virion\Mode:Active";}');
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('serialized class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	/** @return iterable<string, array{string}> */
	public static function autoloading_object_or_class_provider() : iterable
	{
		yield 'is_subclass_of positional object or class' => ['return is_subclass_of($class, \\stdClass::class);'];
		yield 'is_a named allow string' => ['return is_a(object_or_class: $class, class: \\stdClass::class, allow_string: true);'];
	}

	public function test_dynamic_instanceof_target_variable_is_rejected_without_a_static_source() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Matcher.php';
		file_put_contents($path, <<<'PHP'
<?php
namespace Fixture;

final class Matcher
{
    public function matches(object $value, string $className): bool
    {
        return $value instanceof $className;
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
	}

	public function test_static_class_map_factory_is_allowed_and_static_values_are_shaded() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Factory.php';
		file_put_contents($path, <<<'PHP'
<?php
namespace Fixture;

use Shared\Virion\SqlDialect;
use Shared\Virion\MysqlStatement;
use Shared\Virion\SqliteStatement;

final class Factory
{
    public static function create(string $dialect): object
    {
        static $classMap = [
            SqlDialect::MYSQL => MysqlStatement::class,
            SqlDialect::SQLITE => SqliteStatement::class,
        ];
        $className = $classMap[$dialect];
        return new $className();
    }
}
PHP);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('return new $className()', $result);
		self::assertStringNotContainsString('Shared\\Virion', $result);
		self::assertStringContainsString('Fixture\\Shade\\Shared\\MysqlStatement::class', $result);
		self::assertStringContainsString('Fixture\\Shade\\Shared\\SqliteStatement::class', $result);
	}

	public function test_dynamic_class_map_value_remains_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'UnsafeFactory.php', <<<'PHP'
<?php
namespace Fixture;

final class UnsafeFactory
{
    public static function create(string $dialect, string $className): object
    {
        static $classMap = [
            'safe' => $className,
        ];
        $selected = $classMap[$dialect];
        return new $selected();
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_original_class_identity_sentinel_is_preserved() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Packaging.php';
		file_put_contents($path, <<<'PHP'
<?php
namespace Shared\Virion;

final class Packaging
{
    private static bool $packaged;

    public static function detect(): void
    {
        self::$packaged = __CLASS__ !== 'Shared\Virion\Packaging';
    }
}
PHP);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
		$shader->assertNoNamespaceReferences(
			$directory,
			['Shared\Virion'],
			['Shared\Virion' => 'Fixture\Shade\Shared'],
		);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString("'Shared\\Virion\\Packaging'", $result);
		self::assertStringContainsString('namespace Fixture\\Shade\\Shared;', $result);
	}

	public function test_unrelated_class_identity_string_remains_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'UnsafePackaging.php', <<<'PHP'
<?php
namespace Shared\Virion;

final class UnsafePackaging
{
    public static function detect(): bool
    {
        return __CLASS__ !== 'Shared\Virion\Other';
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('string literal');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_class_identity_string_with_an_unrelated_namespace_prefix_is_allowed() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'UnsafePackaging.php', <<<'PHP'
<?php
namespace Shared\Virion;

final class Packaging
{
    public static function detect(): bool
    {
        return __CLASS__ !== 'Other\Shared\Virion\Packaging';
    }
}
PHP);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\Virion']);

		$result = file_get_contents($directory . DIRECTORY_SEPARATOR . 'UnsafePackaging.php');
		self::assertIsString($result);
		self::assertStringContainsString("'Other\\Shared\\Virion\\Packaging'", $result);
	}

	public function test_class_identity_string_with_a_case_mismatch_remains_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'UnsafePackaging.php', <<<'PHP'
<?php
namespace Shared\Virion;

final class Packaging
{
    public static function detect(): bool
    {
        return __CLASS__ !== 'shared\virion\Packaging';
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('string literal');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_computed_instanceof_target_remains_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'UnsafeMatcher.php', <<<'PHP'
<?php
namespace Fixture;

final class UnsafeMatcher
{
    public function matches(object $value, string $className, string $suffix): bool
    {
        return $value instanceof ($className . $suffix);
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
	}

	public function test_dynamic_is_a_target_type_with_autoload_enabled_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Matcher.php', <<<'PHP'
<?php
namespace Fixture;

final class Matcher
{
    public function matches(mixed $throwable, string $class): bool
    {
        return is_a($throwable, $class, true);
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class-name argument to is_a()');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_dynamic_target_to_namespaced_is_a_function_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Matcher.php', <<<'PHP'
<?php
namespace Fixture;

function is_a(mixed $objectOrClass, string $class): bool
{
    return true;
}

final class Matcher
{
    public function matches(mixed $throwable, string $class): bool
    {
        return is_a($throwable, $class);
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class-name argument to is_a()');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_current_fiber_suspend_is_rewritten_to_the_native_static_call() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'GreenThread.php';
		file_put_contents($path, <<<'PHP'
<?php
namespace Fixture;

use Fiber;

final class GreenThread
{
    public function suspendCurrent(): void
    {
        $fiberCurrent = Fiber::getCurrent();
        if ($fiberCurrent !== null) {
            $fiberCurrent::suspend();
        }
    }
}
PHP);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('\Fiber::suspend()', $result);
		self::assertStringNotContainsString('$fiberCurrent::suspend()', $result);

		require_once $path;
		$greenThreadClass = $this->runtimeFixtureClass('GreenThread');
		$fiber = new \Fiber(static function () use ($greenThreadClass) : void {
			$greenThread = (new \ReflectionClass($greenThreadClass))->newInstance();
			(new \ReflectionMethod($greenThread, 'suspendCurrent'))->invoke($greenThread);
		});
		$fiber->start();
		self::assertTrue($fiber->isSuspended());
		$fiber->resume();
		self::assertTrue($fiber->isTerminated());
	}

	public function test_dynamic_static_call_without_exact_current_fiber_guard_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Unsafe.php', <<<'PHP'
<?php
namespace Fixture;

use Fiber;

final class Unsafe
{
    public function suspend(): void
    {
        $fiberCurrent = Fiber::getCurrent();
        if ($fiberCurrent != null) {
            $fiberCurrent::suspend();
        }
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
	}

	public function test_current_fiber_dynamic_method_other_than_suspend_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Unsafe.php', <<<'PHP'
<?php
namespace Fixture;

use Fiber;

final class Unsafe
{
    public function resumeCurrent(): void
    {
        $fiberCurrent = Fiber::getCurrent();
        if ($fiberCurrent !== null) {
            $fiberCurrent::resume();
        }
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
	}

	public function test_reflection_of_late_bound_called_class_uses_the_bound_object() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Thread.php';
		file_put_contents($path, <<<'PHP'
<?php
namespace Fixture;

use Fiber;
use ReflectionClass;
use function get_called_class;

class Thread
{
    public function reflectedClass(): string
    {
        $reflectedClass = '';
        $fiber = new Fiber(function () use (&$reflectedClass): void {
            $className = get_called_class();
            $reflection = new ReflectionClass($className);
            $reflectedClass = $reflection->getName();
        });
        $fiber->start();

        return $reflectedClass;
    }
}

final class ConcreteThread extends Thread
{
}
PHP);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('new ReflectionClass($className)', $result);
		self::assertStringNotContainsString('new ReflectionClass($this)', $result);

		require_once $path;
		$threadClass = $this->runtimeFixtureClass('ConcreteThread');
		$thread = (new \ReflectionClass($threadClass))->newInstance();
		self::assertSame('Fixture\\ConcreteThread', (new \ReflectionMethod($thread, 'reflectedClass'))->invoke($thread));
	}

	public function test_arbitrary_reflection_class_string_is_still_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'UnsafeReflection.php', <<<'PHP'
<?php
namespace Fixture;

use ReflectionClass;

final class UnsafeReflection
{
    public function reflect(string $className): ReflectionClass
    {
        $reflection = new ReflectionClass($className);
        return $reflection;
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic ReflectionClass');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
	}

	public function test_shaded_namespace_with_same_text_prefix_is_not_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Example.php';
		file_put_contents(
			$path,
			"<?php\nnamespace NhanAZ\\FixtureLibraryExample;\nuse NhanAZ\\FixtureLibrary\\FixtureLibrary;\nfinal class Example { private const LABEL = 'NhanAZ\\\\FixtureLibraryExample'; }\n",
		);
		$shader = new NamespaceShader();
		$shader->shadeDirectory(
			$directory,
			['NhanAZ\FixtureLibrary' => 'NhanAZ\FixtureLibraryExample\_DevTools\FixtureLibrary_fixture'],
		);
		$shader->assertNoNamespaceReferences($directory, ['NhanAZ\FixtureLibrary']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('NhanAZ\FixtureLibraryExample\_DevTools\FixtureLibrary_fixture', $result);
		self::assertStringContainsString('NhanAZ\\FixtureLibraryExample', $result);
		self::assertStringNotContainsString('use NhanAZ\FixtureLibrary\FixtureLibrary;', $result);
	}

	public function test_split_dynamic_class_string_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Unsafe.php', "<?php\n\$class = 'Shared\\\\' . 'Virion\\\\Greeting';\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('string concatenation');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_dynamic_static_call_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Unsafe.php', "<?php\n\$class::run();\n");

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_annotated_guarded_environment_driver_is_allowed() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Factory.php';
		file_put_contents(
			$path,
			<<<'PHP'
<?php
namespace Fixture;

use Shared\Virion\Driver;
use function class_exists;
use function getenv;
use function is_subclass_of;

final class Factory
{
    /** @devtools-allow-dynamic-class $driver */
    public function create(): object
    {
        $driver = getenv('DRIVER');
        if (!class_exists($driver)) {
            throw new \Error('missing');
        }
        if (!is_subclass_of($driver, Driver::class)) {
            throw new \Error('invalid');
        }

        return new $driver();
    }
}
PHP,
		);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('Fixture\Shade\Shared\Driver::class', $result);
	}

	public function test_annotated_dynamic_class_without_guards_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents(
			$directory . DIRECTORY_SEPARATOR . 'Unsafe.php',
			<<<'PHP'
<?php
final class Unsafe
{
    /** @devtools-allow-dynamic-class $className */
    public function create(): object
    {
        $className = getenv('CLASS_NAME');

        return new $className();
    }

}
PHP,
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_annotated_dynamic_class_with_unreachable_guards_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents(
			$directory . DIRECTORY_SEPARATOR . 'Unsafe.php',
			<<<'PHP'
<?php
namespace Fixture;
use Shared\Virion\Driver;
final class Unsafe
{
    /** @devtools-allow-dynamic-class $driver */
    public function create(string $driver): object
    {
        if (false) {
            class_exists($driver);
            is_subclass_of($driver, Driver::class);
        }
        return new $driver();
    }
}
PHP,
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_annotated_dynamic_class_with_reassigned_name_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents(
			$directory . DIRECTORY_SEPARATOR . 'Unsafe.php',
			<<<'PHP'
<?php
namespace Fixture;
use Shared\Virion\Driver;
final class Unsafe
{
    /** @devtools-allow-dynamic-class $driver */
    public function create(string $driver): object
    {
        if (!class_exists($driver)) {
            throw new \Error('missing');
        }
        if (!is_subclass_of($driver, Driver::class)) {
            throw new \Error('invalid');
        }
        $driver = getenv('UNVALIDATED_DRIVER');
        return new $driver();
    }
}
PHP,
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');

		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_annotated_dynamic_class_match_is_allowed() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Matcher.php';
		file_put_contents(
			$path,
			<<<'PHP'
<?php
namespace Fixture;

use Shared\Virion\Callback;

final class Matcher
{
    /** @devtools-allow-dynamic-class $callback */
    public function type(object $callback): int
    {
        return match ($callback::class) {
            Callback::class => 1,
            default => 0,
        };
    }
}
PHP,
		);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('Fixture\Shade\Shared\Callback::class', $result);
	}

	public function test_guarded_object_class_without_annotation_is_allowed() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Translator.php';
		file_put_contents(
			$path,
			<<<'PHP'
<?php
namespace Fixture;

use Shared\Virion\Graphic;

final class Translator
{
    public function describe(mixed $graphic): string
    {
        $graphic instanceof Graphic || throw new \InvalidArgumentException();

        return $graphic::class;
    }
}
PHP,
		);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('Fixture\Shade\Shared\Graphic', $result);
		self::assertStringContainsString('$graphic::class', $result);
	}

	public function test_object_class_in_short_circuit_guard_diagnostic_is_allowed() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		$path = $directory . DIRECTORY_SEPARATOR . 'Translator.php';
		file_put_contents(
			$path,
			<<<'PHP'
<?php
namespace Fixture;

use Shared\Virion\Graphic;

final class Translator
{
    public function describe(mixed $graphic): void
    {
        $graphic instanceof Graphic || throw new \InvalidArgumentException(
            "Expected " . Graphic::class . ", got " . $graphic::class,
        );
    }
}
PHP,
		);

		$shader = new NamespaceShader();
		$shader->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
		$shader->assertNoNamespaceReferences($directory, ['Shared\\Virion']);

		$result = file_get_contents($path);
		self::assertIsString($result);
		self::assertStringContainsString('Fixture\\Shade\\Shared\\Graphic::class', $result);
		self::assertStringContainsString('$graphic::class', $result);
	}

	public function test_object_class_in_short_circuit_guard_for_another_variable_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents(
			$directory . DIRECTORY_SEPARATOR . 'Translator.php',
			<<<'PHP'
<?php
namespace Fixture;

use Shared\Virion\Graphic;

final class Translator
{
    public function describe(mixed $graphic, mixed $other): void
    {
        $graphic instanceof Graphic || throw new \InvalidArgumentException($other::class);
    }
}
PHP,
		);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');
		(new NamespaceShader())->shadeDirectory($directory, ['Shared\\Virion' => 'Fixture\\Shade\\Shared']);
	}

	public function test_conditional_object_guard_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Conditional.php', <<<'PHP'
<?php
namespace Fixture;
use Shared\Virion\Graphic;
final class Conditional
{
    public function describe(bool $check, mixed $graphic): string
    {
        if ($check) {
            $graphic instanceof Graphic || throw new \InvalidArgumentException();
        }
        return $graphic::class;
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');
		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	public function test_object_guard_invalidated_by_reassignment_is_rejected() : void
	{
		$directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'src';
		$this->filesystem->ensureDirectory($directory);
		file_put_contents($directory . DIRECTORY_SEPARATOR . 'Reassigned.php', <<<'PHP'
<?php
namespace Fixture;
use Shared\Virion\Graphic;
final class Reassigned
{
    public function describe(mixed $graphic): string
    {
        $graphic instanceof Graphic || throw new \InvalidArgumentException();
        $graphic = Graphic::class;
        return $graphic::class;
    }
}
PHP);

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('dynamic class reference');
		(new NamespaceShader())->shadeDirectory($directory, ['Shared\Virion' => 'Fixture\Shade\Shared']);
	}

	/** @return class-string<object> */
	private function runtimeFixtureClass(string $shortName) : string
	{
		$className = 'Fixture\\' . $shortName;
		if (!class_exists($className)) {
			self::fail('The runtime fixture class was not loaded.');
		}

		return $className;
	}
}
