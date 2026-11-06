<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\BinaryOp\Concat;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\ClassConstFetch;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\FuncCall;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\Instanceof_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\New_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\StaticCall;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Expr\StaticPropertyFetch;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\InterpolatedStringPart;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Name;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Name\FullyQualified;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Scalar\String_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Stmt\GroupUse;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Stmt\Namespace_;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\Node\Stmt\UseUse;
use AxolotlBucketLabs\DevTools\Vendor\PhpParser\NodeVisitorAbstract;

use function ltrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;

final class NamespaceRewriteVisitor extends NodeVisitorAbstract
{
    /**
     * @param array<string, string> $replacements Original namespace => shaded namespace.
     */
    public function __construct(
        private readonly array $replacements,
        private readonly bool $rewriteNamespaceDeclarations = true,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof String_ || $node instanceof InterpolatedStringPart) {
            if (!$node instanceof String_ || !DynamicClassReferencePolicy::allowsOriginalClassIdentityString($node)) {
                foreach ($this->replacements as $original => $replacement) {
                    if (SerializedClassReferenceDetector::contains($node->value, $original)) {
                        throw new BuildException(
                            "Cannot safely shade namespace {$original}: it appears in a serialized class reference on line {$node->getStartLine()}. "
                            . 'Serialized class names cannot be rewritten safely because their byte lengths are encoded.',
                        );
                    }
                    if ($this->containsNamespace($node->value, $original)) {
                        throw new BuildException(
                            "Cannot safely shade namespace {$original}: it appears in a PHP string literal on line {$node->getStartLine()}. "
                            . 'Replace dynamic class-name strings with static ::class references or exclude this virion.',
                        );
                    }
                }
            }

            return null;
        }

        if ($node instanceof Concat) {
            $value = $this->staticString($node);
            if ($value !== null) {
                foreach ($this->replacements as $original => $replacement) {
                    if ($this->containsNamespace($value, $original)) {
                        throw new BuildException(
                            "Cannot safely shade namespace {$original}: it is assembled by string concatenation on line {$node->getStartLine()}.",
                        );
                    }
                }
            }
        }

        if (($node instanceof New_ || $node instanceof Instanceof_ || $node instanceof StaticCall
            || $node instanceof StaticPropertyFetch || $node instanceof ClassConstFetch)
            && $node->class instanceof Expr) {
            if ($node instanceof StaticCall && DynamicClassReferencePolicy::allowsCurrentFiberSuspend($node)) {
                $node->class = new FullyQualified('Fiber');
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
                "Cannot safely shade a dynamic class reference on line {$node->getStartLine()}. Use a static class name or ::class reference.",
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
                        "Cannot safely shade dynamic class reference: dynamic class-name argument to {$function}() on line {$node->getStartLine()}.",
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
                    "Cannot safely shade dynamic {$node->class->toString()} construction on line {$node->getStartLine()}.",
                );
            }
        }

        if ($node instanceof GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                $combined = $prefix . '\\' . $use->name->toString();
                foreach ($this->replacements as $original => $replacement) {
                    if ($this->matchesNamespace($combined, $original)) {
                        throw new BuildException(
                            "Cannot safely shade grouped import {$combined} on line {$node->getStartLine()}. "
                            . 'Replace the grouped import with ordinary use statements.',
                        );
                    }
                }
            }
        }

        if (!$node instanceof Name) {
            return null;
        }

        $parent = $node->getAttribute('parent');
        $isDeclarationOrImport = ($parent instanceof Namespace_ && $parent->name === $node)
            || ($parent instanceof UseUse && $parent->name === $node)
            || ($parent instanceof GroupUse && $parent->prefix === $node);
        if (!$this->rewriteNamespaceDeclarations && $parent instanceof Namespace_ && $parent->name === $node) {
            return null;
        }
        $resolved = $node->getAttribute('resolvedName');
        $candidate = $resolved instanceof Name ? $resolved->toString() : $node->toString();
        $replacement = $this->replacementFor(
            $candidate,
            ($parent instanceof Namespace_ && $parent->name === $node)
                || ($parent instanceof GroupUse && $parent->prefix === $node),
        );
        if ($replacement === null) {
            return null;
        }

        if ($isDeclarationOrImport) {
            if ($parent instanceof Namespace_) {
                $parent->setAttribute('devtoolsOriginalName', $node->toString());
            }
            return new Name($replacement, $node->getAttributes());
        }

        if ($node instanceof FullyQualified || $resolved instanceof Name) {
            return new FullyQualified($replacement, $node->getAttributes());
        }

        return null;
    }

    private function replacementFor(string $candidate, bool $allowExactNamespace): ?string
    {
        foreach ($this->replacements as $original => $replacement) {
            if ($this->matchesNamespace($candidate, $original, $allowExactNamespace)) {
                return $replacement . substr($candidate, strlen($original));
            }
        }

        return null;
    }

    private function matchesNamespace(string $candidate, string $namespace, bool $allowExactNamespace = true): bool
    {
        $candidate = strtolower(ltrim($candidate, '\\'));
        $namespace = strtolower(ltrim($namespace, '\\'));

        return ($allowExactNamespace && $candidate === $namespace) || str_starts_with($candidate, $namespace . '\\');
    }

    private function containsNamespace(string $value, string $namespace): bool
    {
        $value = ltrim(strtolower(str_replace('\\\\', '\\', $value)), '\\');
        $namespace = strtolower(ltrim($namespace, '\\'));

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

    private function isStaticClassExpression(Expr $expression): bool
    {
        return $this->staticString($expression) !== null
            || ($expression instanceof ClassConstFetch && $expression->class instanceof Name)
            || ($expression instanceof New_ && $expression->class instanceof Name);
    }

    private function staticString(Expr $expression): ?string
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
}
