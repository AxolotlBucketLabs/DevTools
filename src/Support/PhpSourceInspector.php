<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Support;

use function file_get_contents;
use function in_array;
use function is_array;
use function ltrim;

use ParseError;

use function token_get_all;
use function trim;

final class PhpSourceInspector
{
    public function inspect(string $path): PhpFileSymbols
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new FilesystemException("Cannot read PHP source: {$path}");
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new FilesystemException("PHP syntax error in {$path}: {$error->getMessage()}", 0, $error);
        }

        $namespace = '';
        $namespaces = [];
        $classes = [];
        $functions = [];
        $constants = [];
        $classBraceDepths = [];
        $interpolationBraceDepth = 0;
        $pendingClass = false;
        $braceDepth = 0;
        $previousSignificant = null;
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                if ($token === '}' && $interpolationBraceDepth > 0) {
                    --$interpolationBraceDepth;
                    continue;
                }
                if ($token === '{') {
                    ++$braceDepth;
                    if ($pendingClass) {
                        $classBraceDepths[] = $braceDepth;
                        $pendingClass = false;
                    }
                } elseif ($token === '}') {
                    if ($classBraceDepths !== [] && end($classBraceDepths) === $braceDepth) {
                        array_pop($classBraceDepths);
                    }
                    --$braceDepth;
                }
                if (trim($token) !== '') {
                    $previousSignificant = $token;
                }
                continue;
            }

            [$id] = $token;
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$interpolationBraceDepth;
            } elseif ($id === T_NAMESPACE) {
                $namespace = $this->readName($tokens, $index);
                if (!in_array($namespace, $namespaces, true)) {
                    $namespaces[] = $namespace;
                }
            } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $pendingClass = true;
                if (!in_array($previousSignificant, [T_NEW, T_DOUBLE_COLON], true)) {
                    $name = $this->readDeclarationName($tokens, $index);
                    if ($name !== null) {
                        $classes[] = ltrim($namespace . '\\' . $name, '\\');
                    }
                }
            } elseif ($id === T_FUNCTION) {
                $classBodyDepth = $classBraceDepths === [] ? null : end($classBraceDepths);
                if ($classBodyDepth === null || $classBodyDepth !== $braceDepth) {
                    $name = $this->readDeclarationName($tokens, $index);
                    if ($name !== null) {
                        $functions[] = ltrim($namespace . '\\' . $name, '\\');
                    }
                }
            } elseif ($id === T_CONST && $classBraceDepths === []) {
                foreach ($this->readConstantNames($tokens, $index, $namespace) as $constant) {
                    $constants[] = $constant;
                }
            }

            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previousSignificant = $id;
            }
        }

        return new PhpFileSymbols($classes, $namespaces, array_values(array_unique($functions)), array_values(array_unique($constants)));
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function readName(array $tokens, int &$index): string
    {
        $name = '';
        $count = count($tokens);
        for (++$index; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if ($token === ';' || $token === '{') {
                    break;
                }
                continue;
            }
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
            }
        }

        return ltrim($name, '\\');
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function readDeclarationName(array $tokens, int $index): ?string
    {
        $count = count($tokens);
        for (++$index; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token === '&' || (is_array($token) && in_array($token[0], [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true))) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<string>
     */
    private function readConstantNames(array $tokens, int $index, string $namespace): array
    {
        $constants = [];
        $expectName = true;
        $count = count($tokens);
        for (++$index; $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === ';') {
                break;
            }
            if ($token === ',') {
                $expectName = true;
                continue;
            }
            if ($token === '=') {
                $expectName = false;
                continue;
            }
            if ($expectName && is_array($token) && $token[0] === T_STRING) {
                $constants[] = ltrim($namespace . '\\' . $token[1], '\\');
                $expectName = false;
            }
        }

        return $constants;
    }
}
