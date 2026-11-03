<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Support;

use function file_get_contents;
use function in_array;
use function is_array;
use function ltrim;

use ParseError;

use function preg_match;
use function stripcslashes;
use function strtolower;
use function substr;
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
        $unconditionalFunctions = [];
        $constants = [];
        $classBraceDepths = [];
        /** @var list<array{depth: int, guards: list<string>}> $conditionalFunctionGuards */
        $conditionalFunctionGuards = [];
        $interpolationBraceDepth = 0;
        $pendingClass = false;
        $pendingClassParenthesisDepth = null;
        /** @var list<string>|null $pendingFunctionGuards */
        $pendingFunctionGuards = null;
        $parenthesisDepth = 0;
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
                if ($token === '(') {
                    ++$parenthesisDepth;
                } elseif ($token === ')') {
                    --$parenthesisDepth;
                } elseif ($token === '{') {
                    ++$braceDepth;
                    if ($pendingClass && $pendingClassParenthesisDepth === $parenthesisDepth) {
                        $classBraceDepths[] = $braceDepth;
                        $pendingClass = false;
                        $pendingClassParenthesisDepth = null;
                    }
                    if ($pendingFunctionGuards !== null) {
                        $conditionalFunctionGuards[] = [
                            'depth' => $braceDepth,
                            'guards' => $pendingFunctionGuards,
                        ];
                        $pendingFunctionGuards = null;
                    }
                } elseif ($token === '}') {
                    if ($conditionalFunctionGuards !== []
                        && end($conditionalFunctionGuards)['depth'] === $braceDepth) {
                        array_pop($conditionalFunctionGuards);
                    }
                    if ($classBraceDepths !== [] && end($classBraceDepths) === $braceDepth) {
                        array_pop($classBraceDepths);
                    }
                    --$braceDepth;
                } elseif ($token === ';' || $token === ':') {
                    $pendingFunctionGuards = null;
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
            } elseif ($id === T_IF) {
                $pendingFunctionGuards = $this->conditionFunctionGuardNames($tokens, $index);
            } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $isClassNameReference = $previousSignificant === T_DOUBLE_COLON;
                $pendingClass = !$isClassNameReference;
                $pendingClassParenthesisDepth = $pendingClass ? $parenthesisDepth : null;
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
                        $fullName = ltrim($namespace . '\\' . $name, '\\');
                        $functions[] = $fullName;
                        if (!$this->isFunctionGuarded($fullName, $conditionalFunctionGuards)) {
                            $unconditionalFunctions[] = $fullName;
                        }
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

        return new PhpFileSymbols(
            $classes,
            $namespaces,
            array_values(array_unique($functions)),
            array_values(array_unique($constants)),
            array_values(array_unique($unconditionalFunctions)),
        );
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<string>|null
     */
    private function conditionFunctionGuardNames(array $tokens, int $index): ?array
    {
        $condition = [];
        $parentheses = 0;
        $count = count($tokens);
        for (++$index; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token !== '(') {
                return null;
            }
            $parentheses = 1;
            for (++$index; $index < $count; ++$index) {
                $token = $tokens[$index];
                if (is_string($token)) {
                    if ($token === '(') {
                        ++$parentheses;
                    } elseif ($token === ')') {
                        --$parentheses;
                        if ($parentheses === 0) {
                            break;
                        }
                    }
                }
                if (!(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                    $condition[] = $token;
                }
            }
            break;
        }
        if ($condition === [] || $parentheses !== 0) {
            return null;
        }

        $position = 0;
        if ($condition[0] !== '!') {
            return null;
        }
        ++$position;
        while (($condition[$position] ?? null) === '(') {
            ++$position;
        }
        $function = $condition[$position] ?? null;
        if (!is_array($function)
            || !in_array($function[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            || strtolower(ltrim($function[1], '\\')) !== 'function_exists') {
            return null;
        }
        ++$position;
        if (($condition[$position] ?? null) !== '(') {
            return null;
        }
        ++$position;
        $nameToken = $condition[$position] ?? null;
        if (!is_array($nameToken) || $nameToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $rawName = $nameToken[1];
        $quote = $rawName[0] ?? '';
        if (($quote !== "'" && $quote !== '"') || substr($rawName, -1) !== $quote) {
            return null;
        }
        $name = ltrim(stripcslashes(substr($rawName, 1, -1)), '\\');
        if ($name === '' || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            return null;
        }
        ++$position;
        if (($condition[$position] ?? null) !== ')') {
            return null;
        }
        ++$position;
        while (($condition[$position] ?? null) === '(') {
            ++$position;
        }
        while (($condition[$position] ?? null) === ')') {
            ++$position;
        }
        if ($position !== count($condition)) {
            return null;
        }

        return [strtolower($name)];
    }

    /**
     * @param list<array{depth: int, guards: list<string>}> $conditionalFunctionGuards
     */
    private function isFunctionGuarded(string $function, array $conditionalFunctionGuards): bool
    {
        $function = strtolower(ltrim($function, '\\'));
        foreach ($conditionalFunctionGuards as $conditional) {
            foreach ($conditional['guards'] as $guard) {
                if ($function === $guard) {
                    return true;
                }
            }
        }

        return false;
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
