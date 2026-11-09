<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\BinaryOp\Concat;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\ClassConstFetch;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\ConstFetch;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\FuncCall;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\Instanceof_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\New_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\StaticCall;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\StaticPropertyFetch;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\InterpolatedStringPart;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Name;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Scalar\String_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Stmt\Namespace_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\NodeVisitorAbstract;
use function in_array;
use function ltrim;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;

final class NamespaceReferenceGuardVisitor extends NodeVisitorAbstract
{
	/**
	 * @param list<string>          $namespaces
	 * @param array<string, string> $shadedNamespaces Original namespace => shaded namespace.
	 */
	public function __construct(
		private readonly array $namespaces,
		private readonly array $shadedNamespaces = [],
	) {}

	public function enterNode(Node $node) : ?Node
	{
		if ($node instanceof Name) {
			$parent = $node->getAttribute('parent');
			if ($parent instanceof Namespace_ && $parent->name === $node) {
				return null;
			}
			$resolved = $node->getAttribute('resolvedName');
			$candidate = $resolved instanceof Name ? $resolved->toString() : $node->toString();
			if ($parent instanceof ConstFetch && $this->isExactNamespace($candidate)) {
				return null;
			}
			$this->assertValueIsStandalone($candidate, $node->getStartLine());
		} elseif ($node instanceof String_ || $node instanceof InterpolatedStringPart) {
			if (!$node instanceof String_
				|| !DynamicClassReferencePolicy::allowsOriginalClassIdentityString($node, $this->shadedNamespaces)) {
				$this->assertValueIsStandalone($node->value, $node->getStartLine());
			}
		} elseif ($node instanceof Concat) {
			$value = $this->staticString($node);
			if ($value !== null) {
				$this->assertValueIsStandalone($value, $node->getStartLine());
			}
		}

		if (($node instanceof New_ || $node instanceof Instanceof_ || $node instanceof StaticCall
			|| $node instanceof StaticPropertyFetch || $node instanceof ClassConstFetch)
			&& $node->class instanceof Expr) {
			if ($node instanceof StaticCall && DynamicClassReferencePolicy::allowsCurrentFiberSuspend($node)) {
				return null;
			}
			if ($node instanceof ClassConstFetch && DynamicClassReferencePolicy::allowsObjectClass($node)) {
				return null;
			}
			if ($node instanceof New_ && DynamicClassReferencePolicy::allowsGuarded($node, $node->class)) {
				return null;
			}
			if ($node instanceof New_ && DynamicClassReferencePolicy::allowsStaticClassMapFactory($node)) {
				return null;
			}
			throw new BuildException(
				"Cannot prove that a dynamic class reference on line {$node->getStartLine()} is independent of an undeclared virion.",
			);
		}

		if ($node instanceof FuncCall && $node->name instanceof Name) {
			$function = DynamicClassReferencePolicy::resolvedName($node->name);
			foreach (DynamicClassReferencePolicy::classNameArguments($node) as $argument) {
				if ($argument->unpack || (!$this->isStaticClassExpression($argument->value)
					&& !($function === 'is_subclass_of' && DynamicClassReferencePolicy::allowsNonAutoloadingIsSubclassOfObjectOrClassArgument($node))
					&& !($function === 'class_exists'
						&& DynamicClassReferencePolicy::allowsAnnotated($node, $argument->value)))) {
					throw new BuildException(
						"Cannot prove that dynamic class reference: dynamic class-name argument to {$function}() on line {$node->getStartLine()} is independent of an undeclared virion.",
					);
				}
			}
		}

		if ($node instanceof New_ && $node->class instanceof Name) {
			$argument = DynamicClassReferencePolicy::reflectionClassNameArgument($node);
			$class = DynamicClassReferencePolicy::resolvedName($node->class);
			if ($argument !== null && in_array($class, ['reflectionclass', 'reflectionenum', 'reflectionmethod', 'reflectionproperty', 'reflectionclassconstant'], true)
				&& !DynamicClassReferencePolicy::allowsCalledClassReflection($node)
				&& !DynamicClassReferencePolicy::allowsBoundObjectReflection($node)
				&& !$this->isStaticClassExpression($argument->value)) {
				throw new BuildException(
					"Cannot prove that dynamic {$node->class->toString()} construction on line {$node->getStartLine()} is independent of an undeclared virion.",
				);
			}
		}

		return null;
	}

	private function assertValueIsStandalone(string $value, int $line) : void
	{
		$value = strtolower(str_replace('\\\\', '\\', ltrim($value, '\\')));
		foreach ($this->namespaces as $namespace) {
			$namespace = strtolower(ltrim($namespace, '\\'));
			if ($this->containsNamespaceReference($value, $namespace)) {
				throw new BuildException(
					"Reference to development-only namespace {$namespace} remains on line {$line}. "
					. 'Declare and shade its virion, or remove the runtime dependency.',
				);
			}
		}
	}

	private function containsNamespaceReference(string $value, string $namespace) : bool
	{
		if (SerializedClassReferenceDetector::contains($value, $namespace)) {
			return true;
		}
		$offset = 0;
		while (($position = strpos($value, $namespace, $offset)) !== false) {
			$end = $position + strlen($namespace);
			if ($position === 0 && ($end === strlen($value) || $value[$end] === '\\')) {
				return true;
			}
			$offset = $position + 1;
		}

		return false;
	}

	private function isExactNamespace(string $candidate) : bool
	{
		$candidate = strtolower(ltrim($candidate, '\\'));
		foreach ($this->namespaces as $namespace) {
			if ($candidate === strtolower(ltrim($namespace, '\\'))) {
				return true;
			}
		}

		return false;
	}

	private function staticString(Expr $expression) : ?string
	{
		if ($expression instanceof String_) {
			return $expression->value;
		}
		if (!$expression instanceof Concat) {
			return null;
		}
		$left = $this->staticString($expression->left);
		$right = $this->staticString($expression->right);

		return $left === null || $right === null ? null : $left . $right;
	}

	private function isStaticClassExpression(Expr $expression) : bool
	{
		return $this->staticString($expression) !== null
			|| ($expression instanceof ClassConstFetch && $expression->class instanceof Name)
			|| ($expression instanceof New_ && $expression->class instanceof Name);
	}
}
