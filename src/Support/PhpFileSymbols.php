<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Support;

final class PhpFileSymbols
{
    /**
     * @param list<string> $classes
     * @param list<string> $namespaces
     * @param list<string> $functions
     * @param list<string> $constants
     * @param list<string> $unconditionalFunctions
     */
    public function __construct(
        public readonly array $classes,
        public readonly array $namespaces,
        public readonly array $functions = [],
        public readonly array $constants = [],
        public readonly array $unconditionalFunctions = [],
    ) {}
}
