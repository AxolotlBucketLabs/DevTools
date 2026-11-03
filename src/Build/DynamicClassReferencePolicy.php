<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function array_search;
use function count;
use function in_array;
use function is_string;
use function ltrim;

use NhanAZ\DevTools\Vendor\PhpParser\Node;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Arg;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\Array_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\ArrayDimFetch;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\ArrowFunction;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\Assign;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\BinaryOp\BooleanOr;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\BinaryOp\NotIdentical;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\BooleanNot;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\ClassConstFetch;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\Closure;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\ConstFetch;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\FuncCall;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\Instanceof_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\New_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\StaticCall;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\Throw_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Expr\Variable;
use NhanAZ\DevTools\Vendor\PhpParser\Node\FunctionLike;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Identifier;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Name;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Scalar\MagicConst\Class_ as ClassMagicConst;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Scalar\String_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Stmt\Class_ as ClassStatement;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Stmt\ClassMethod;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Stmt\Expression as ExpressionStatement;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Stmt\If_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Stmt\Namespace_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Stmt\Return_;
use NhanAZ\DevTools\Vendor\PhpParser\Node\Stmt\Static_;

use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Allows only explicitly annotated and validated runtime class factories.
 *
 * The annotation is intentionally attached to the function that owns the
 * dynamic class reference. It is not a general bypass for namespace safety.
 */
final class DynamicClassReferencePolicy
{
    public static function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        return strtolower(ltrim(($resolved instanceof Name ? $resolved : $name)->toString(), '\\'));
    }

    /** @return list<Arg> */
    public static function classNameArguments(FuncCall $node): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }
        $parameters = match (self::resolvedName($node->name)) {
            'class_alias' => [0 => 'class', 1 => 'alias'],
            'class_exists' => [0 => 'class'],
            'interface_exists' => [0 => 'interface'],
            'trait_exists' => [0 => 'trait'],
            'enum_exists' => [0 => 'enum'],
            'is_a' => self::allowsStringMode($node)
                ? [0 => 'object_or_class', 1 => 'class']
                : [1 => 'class'],
            'is_subclass_of' => [0 => 'object_or_class', 1 => 'class'],
            default => [],
        };
        if ($parameters === []) {
            return [];
        }
        $arguments = [];
        foreach ($node->args as $position => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }
            if ($argument->unpack
                || ($argument->name === null ? isset($parameters[$position]) : in_array($argument->name->toString(), $parameters, true))) {
                $arguments[] = $argument;
            }
        }
        return $arguments;
    }

    /**
     * The first operand of is_subclass_of() may be an object or a class name.
     * A variable is safe only after a throwing class_exists() guard has loaded
     * the class name, which preserves the existing validated factory pattern.
     */
    public static function allowsNonAutoloadingIsSubclassOfObjectOrClassArgument(FuncCall $node): bool
    {
        if (!$node->name instanceof Name || self::resolvedName($node->name) !== 'is_subclass_of'
            || !isset($node->args[0]) || !$node->args[0] instanceof Arg
            || !$node->args[0]->value instanceof Variable
            || !is_string($node->args[0]->value->name)) {
            return false;
        }

        $variable = $node->args[0]->value->name;
        $parent = $node->getAttribute('parent');
        if (!$parent instanceof BooleanNot || $parent->expr !== $node) {
            return false;
        }
        $statement = $parent->getAttribute('parent');
        if (!$statement instanceof If_ || !self::isThrowingGuard($statement, 'is_subclass_of', $variable)) {
            return false;
        }
        $function = self::enclosingFunction($node);
        if ($function === null) {
            return false;
        }
        $statements = $function->getStmts() ?? [];
        $index = array_search($statement, $statements, true);

        return is_int($index) && $index > 0
            && self::isThrowingGuard($statements[$index - 1], 'class_exists', $variable);
    }

    private static function allowsStringMode(FuncCall $node): bool
    {
        $argument = self::argument($node, 2, 'allow_string');
        if ($argument === null) {
            return false;
        }

        return !($argument->value instanceof ConstFetch
            && strtolower($argument->value->name->toString()) === 'false');
    }

    private static function argument(FuncCall $node, int $position, string $name): ?Arg
    {
        foreach ($node->args as $index => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }
            if (($argument->name === null && $index === $position)
                || ($argument->name !== null && $argument->name->toString() === $name)) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * Allows rewriting the exact Fiber::getCurrent(), non-null guard, suspend pattern.
     * The native Fiber is final, getCurrent() returns Fiber|null, and suspend() targets
     * the currently executing Fiber, so this dynamic object-class call is equivalent to
     * a direct \Fiber::suspend() call. Other dynamic static calls remain rejected.
     */
    public static function allowsCurrentFiberSuspend(StaticCall $node): bool
    {
        if (!$node->class instanceof Variable || !is_string($node->class->name)
            || !$node->name instanceof Identifier || strtolower($node->name->toString()) !== 'suspend'
            || $node->args !== []) {
            return false;
        }

        $variable = $node->class->name;
        $statement = $node->getAttribute('parent');
        if (!$statement instanceof ExpressionStatement || $statement->expr !== $node) {
            return false;
        }

        $guard = $statement->getAttribute('parent');
        if (!$guard instanceof If_ || $guard->else !== null || $guard->elseifs !== []
            || count($guard->stmts) !== 1 || $guard->stmts[0] !== $statement
            || !$guard->cond instanceof NotIdentical
            || !$guard->cond->left instanceof Variable || $guard->cond->left->name !== $variable
            || !$guard->cond->right instanceof ConstFetch
            || strtolower($guard->cond->right->name->toString()) !== 'null') {
            return false;
        }

        $function = self::enclosingFunction($node);
        if ($function === null) {
            return false;
        }

        $statements = $function->getStmts() ?? [];
        $index = array_search($guard, $statements, true);
        if (!is_int($index) || $index === 0
            || !$statements[$index - 1] instanceof ExpressionStatement
            || !$statements[$index - 1]->expr instanceof Assign
            || !$statements[$index - 1]->expr->var instanceof Variable
            || $statements[$index - 1]->expr->var->name !== $variable
            || !$statements[$index - 1]->expr->expr instanceof StaticCall) {
            return false;
        }

        $currentFiber = $statements[$index - 1]->expr->expr;
        if (!$currentFiber->class instanceof Name || !$currentFiber->name instanceof Identifier
            || strtolower($currentFiber->name->toString()) !== 'getcurrent' || $currentFiber->args !== []) {
            return false;
        }

        return self::isGlobalName($currentFiber->class, 'fiber');
    }

    /**
     * Allows replacing ReflectionClass($className) only when the same non-static closure
     * has just assigned get_called_class() to that variable. In this exact instance-method
     * context, reflecting $this preserves the late-bound class without a dynamic class string.
     */
    public static function allowsCalledClassReflection(New_ $node): bool
    {
        if (!$node->class instanceof Name || !self::isGlobalName($node->class, 'reflectionclass')
            || count($node->args) !== 1 || !$node->args[0] instanceof Arg || $node->args[0]->unpack
            || $node->args[0]->name !== null || !$node->args[0]->value instanceof Variable
            || !is_string($node->args[0]->value->name)) {
            return false;
        }

        $reflectionAssignment = $node->getAttribute('parent');
        if (!$reflectionAssignment instanceof Assign || $reflectionAssignment->expr !== $node) {
            return false;
        }

        $statement = $reflectionAssignment->getAttribute('parent');
        $function = self::enclosingFunction($node);
        if (!$statement instanceof ExpressionStatement || $statement->expr !== $reflectionAssignment
            || !$function instanceof Closure || $function->static || !self::hasBoundThis($node)) {
            return false;
        }

        $statements = $function->getStmts();
        $index = array_search($statement, $statements, true);
        if (!is_int($index) || $index === 0
            || !$statements[$index - 1] instanceof ExpressionStatement
            || !$statements[$index - 1]->expr instanceof Assign
            || !$statements[$index - 1]->expr->var instanceof Variable
            || $statements[$index - 1]->expr->var->name !== $node->args[0]->value->name
            || !$statements[$index - 1]->expr->expr instanceof FuncCall) {
            return false;
        }

        $calledClass = $statements[$index - 1]->expr->expr;
        return $calledClass->name instanceof Name && $calledClass->args === []
            && self::isGlobalName($calledClass->name, 'get_called_class');
    }

    /** ReflectionClass accepts an already-instantiated object without class-name autoloading. */
    public static function allowsBoundObjectReflection(New_ $node): bool
    {
        return $node->class instanceof Name && self::isGlobalName($node->class, 'reflectionclass')
            && count($node->args) === 1 && $node->args[0] instanceof Arg && !$node->args[0]->unpack
            && $node->args[0]->name === null && $node->args[0]->value instanceof Variable
            && $node->args[0]->value->name === 'this' && self::hasBoundThis($node);
    }

    private static function hasBoundThis(Node $node): bool
    {
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof Closure || $parent instanceof ArrowFunction) {
                if ($parent->static) {
                    return false;
                }
            } elseif ($parent instanceof ClassMethod) {
                return !$parent->isStatic();
            } elseif ($parent instanceof FunctionLike) {
                return false;
            }
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    private static function isGlobalName(Name $name, string $expected): bool
    {
        $resolvedName = $name->getAttribute('resolvedName');
        return $resolvedName instanceof Name && $resolvedName->isFullyQualified()
            && strtolower(ltrim($resolvedName->toString(), '\\')) === $expected;
    }

    public static function allowsAnnotated(Node $node, Expr $class): bool
    {
        $variable = self::variableName($class);
        $function = self::enclosingFunction($node);
        if ($variable === null || $function === null) {
            return false;
        }

        $comment = $function->getDocComment();
        if ($comment === null) {
            return false;
        }

        return str_contains($comment->getText(), '@devtools-allow-dynamic-class $' . $variable);
    }

    public static function allowsGuarded(Node $node, Expr $class): bool
    {
        if (!self::allowsAnnotated($node, $class)) {
            return false;
        }

        $variable = self::variableName($class);
        $function = self::enclosingFunction($node);
        if ($variable === null || $function === null) {
            return false;
        }

        $parent = $node->getAttribute('parent');
        if (!$parent instanceof Return_ || $parent->expr !== $node) {
            return false;
        }

        $statements = $function->getStmts() ?? [];
        $index = array_search($parent, $statements, true);
        return is_int($index)
            && $index >= 2
            && self::isThrowingGuard($statements[$index - 2], 'class_exists', $variable)
            && self::isThrowingGuard($statements[$index - 1], 'is_subclass_of', $variable);
    }

    private static function isThrowingGuard(Node $statement, string $function, string $variable): bool
    {
        if (!$statement instanceof If_ || $statement->else !== null || $statement->elseifs !== []
            || count($statement->stmts) !== 1
            || !$statement->stmts[0] instanceof ExpressionStatement
            || !$statement->stmts[0]->expr instanceof Throw_
            || !$statement->cond instanceof BooleanNot
            || !$statement->cond->expr instanceof FuncCall) {
            return false;
        }

        $call = $statement->cond->expr;
        $name = $call->name;
        if (!$name instanceof Name
            || strtolower(ltrim($name->toString(), '\\')) !== $function
            || !isset($call->args[0]) || !$call->args[0] instanceof Arg
            || !$call->args[0]->value instanceof Variable
            || $call->args[0]->value->name !== $variable) {
            return false;
        }

        if ($function === 'is_subclass_of') {
            return isset($call->args[1]) && $call->args[1] instanceof Arg
                && $call->args[1]->value instanceof ClassConstFetch
                && $call->args[1]->value->class instanceof Name;
        }
        return true;
    }

    public static function allowsObjectClass(Node $node): bool
    {
        if (!$node instanceof ClassConstFetch
            || !$node->name instanceof Identifier
            || $node->name->toString() !== 'class'
            || !$node->class instanceof Variable) {
            return false;
        }

        return self::allowsAnnotated($node, $node->class)
            || self::hasGuardedObjectClass($node, $node->class)
            || self::hasInlineGuardedObjectClass($node, $node->class);
    }

    /**
     * Allows a factory that selects a class only from a local static map whose
     * values are literal ::class references immediately before the dynamic new.
     * The exact layout prevents callers from injecting arbitrary class strings
     * or mutating the map before construction.
     */
    public static function allowsStaticClassMapFactory(New_ $node): bool
    {
        if (!$node->class instanceof Variable || !is_string($node->class->name)) {
            return false;
        }

        $return = $node->getAttribute('parent');
        $function = self::enclosingFunction($node);
        if (!$return instanceof Return_ || $return->expr !== $node || $function === null) {
            return false;
        }

        $statements = $function->getStmts() ?? [];
        $index = array_search($return, $statements, true);
        if (!is_int($index) || $index < 2
            || !$statements[$index - 1] instanceof ExpressionStatement
            || !$statements[$index - 1]->expr instanceof Assign
            || !$statements[$index - 2] instanceof Static_) {
            return false;
        }

        $assignment = $statements[$index - 1]->expr;
        if (!$assignment->var instanceof Variable || $assignment->var->name !== $node->class->name
            || !$assignment->expr instanceof ArrayDimFetch
            || !$assignment->expr->var instanceof Variable
            || !is_string($assignment->expr->var->name)) {
            return false;
        }

        $static = $statements[$index - 2];
        if (count($static->vars) !== 1
            || $static->vars[0]->var->name !== $assignment->expr->var->name
            || !$static->vars[0]->default instanceof Array_) {
            return false;
        }

        $items = $static->vars[0]->default->items;
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if ($item->unpack || !$item->value instanceof ClassConstFetch
                || !$item->value->class instanceof Name
                || !$item->value->name instanceof Identifier
                || strtolower($item->value->name->toString()) !== 'class') {
                return false;
            }
        }

        return true;
    }

    /**
     * Preserves a virion's `__CLASS__ !== 'original\\Class'` packaging sentinel.
     * The literal must be the exact fully-qualified enclosing class name; it is
     * a comparison marker, not a runtime class lookup that should be shaded.
     * @param array<string, string> $shadedNamespaces Original namespace => shaded namespace.
     */
    public static function allowsOriginalClassIdentityString(String_ $node, array $shadedNamespaces = []): bool
    {
        $comparison = $node->getAttribute('parent');
        if (!$comparison instanceof NotIdentical || $comparison->right !== $node
            || !$comparison->left instanceof ClassMagicConst) {
            return false;
        }

        $class = self::enclosingClass($node);
        if ($class === null || $class->name === null) {
            return false;
        }

        $namespace = $class->getAttribute('parent');
        while ($namespace instanceof Node && !$namespace instanceof Namespace_) {
            $namespace = $namespace->getAttribute('parent');
        }

        $classNamespace = '';
        if ($namespace instanceof Namespace_ && $namespace->name instanceof Name) {
            $originalNamespace = $namespace->getAttribute('devtoolsOriginalName');
            if (is_string($originalNamespace)) {
                $classNamespace = $originalNamespace;
            } else {
                $namespaceName = $namespace->name->getAttribute('resolvedName');
                if (!$namespaceName instanceof Name) {
                    $namespaceName = $namespace->name;
                }
                $currentNamespace = $namespaceName->toString();
                $classNamespace = $currentNamespace;
                $longestMatch = 0;
                foreach ($shadedNamespaces as $original => $shaded) {
                    $shaded = ltrim($shaded, '\\');
                    $normalizedShaded = strtolower($shaded);
                    $normalizedCurrent = strtolower(ltrim($currentNamespace, '\\'));
                    if ($normalizedCurrent !== $normalizedShaded
                        && !str_starts_with($normalizedCurrent, $normalizedShaded . '\\')) {
                        continue;
                    }
                    $matchLength = strlen($shaded);
                    if ($matchLength <= $longestMatch) {
                        continue;
                    }
                    $suffix = substr(ltrim($currentNamespace, '\\'), $matchLength);
                    $classNamespace = ltrim($original, '\\') . $suffix;
                    $longestMatch = $matchLength;
                }
            }
        }

        $className = $classNamespace === ''
            ? $class->name->toString()
            : $classNamespace . '\\' . $class->name->toString();

        return $node->value === $className;
    }

    private static function hasGuardedObjectClass(Node $node, Variable $class): bool
    {
        $function = self::enclosingFunction($node);
        $parent = $node->getAttribute('parent');
        if ($function === null || !$parent instanceof Return_ || $parent->expr !== $node) {
            return false;
        }

        $statements = $function->getStmts() ?? [];
        $index = array_search($parent, $statements, true);
        if (!is_int($index) || $index === 0) {
            return false;
        }

        $previous = $statements[$index - 1];
        if (!$previous instanceof ExpressionStatement || !$previous->expr instanceof BooleanOr) {
            return false;
        }

        $guard = $previous->expr;
        return $guard->left instanceof Instanceof_
            && $guard->right instanceof Throw_
            && $guard->left->expr instanceof Variable
            && $guard->left->expr->name === $class->name
            && $guard->left->class instanceof Name;
    }

    /**
     * Allows an object-class diagnostic inside the right side of the same
     * short-circuit `instanceof ... || throw` guard. The right side cannot run
     * after a successful type check, and object-class inspection does not load
     * or resolve a class-name string for shading.
     */
    private static function hasInlineGuardedObjectClass(ClassConstFetch $node, Variable $class): bool
    {
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof BooleanOr) {
                return $parent->left instanceof Instanceof_
                    && $parent->right instanceof Throw_
                    && $parent->left->expr instanceof Variable
                    && $parent->left->expr->name === $class->name
                    && $parent->left->class instanceof Name;
            }
            if ($parent instanceof FunctionLike) {
                return false;
            }
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    private static function enclosingClass(Node $node): ?ClassStatement
    {
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof ClassStatement) {
                return $parent;
            }
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    private static function variableName(Expr $class): ?string
    {
        return $class instanceof Variable && is_string($class->name) ? $class->name : null;
    }

    private static function enclosingFunction(Node $node): ?FunctionLike
    {
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof FunctionLike) {
                return $parent;
            }
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }
}
