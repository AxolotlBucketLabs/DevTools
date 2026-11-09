<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use ParseError;
use function array_pop;
use function array_unique;
use function array_values;
use function chr;
use function count;
use function end;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function ord;
use function preg_match;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function token_get_all;
use function trim;
use const T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG;
use const T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
use const T_CLASS;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_COLON;
use const T_ENUM;
use const T_FUNCTION;
use const T_IF;
use const T_INTERFACE;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use const T_WHITESPACE;
use const TOKEN_PARSE;

final class PhpSourceInspector
{
	public function inspect(string $path) : PhpFileSymbols
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
		/** @var list<int> $pendingClassParenthesisDepths */
		$pendingClassParenthesisDepths = [];
		/** @var list<string>|null $pendingFunctionGuards */
		$pendingFunctionGuards = null;
		$parenthesisDepth = 0;
		$braceDepth = 0;
		$inUseStatement = false;
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
					if ($pendingClassParenthesisDepths !== []
						&& end($pendingClassParenthesisDepths) === $parenthesisDepth) {
						$classBraceDepths[] = $braceDepth;
						array_pop($pendingClassParenthesisDepths);
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
				} elseif ($token === ';') {
					$pendingFunctionGuards = null;
					$inUseStatement = false;
				} elseif ($token === ':') {
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
				if (!$isClassNameReference) {
					$pendingClassParenthesisDepths[] = $parenthesisDepth;
				}
				if (!in_array($previousSignificant, [T_NEW, T_DOUBLE_COLON], true)) {
					$name = $this->readDeclarationName($tokens, $index);
					if ($name !== null) {
						$classes[] = ltrim($namespace . '\\' . $name, '\\');
					}
				}
			} elseif ($id === T_USE) {
				$inUseStatement = $classBraceDepths === []
					&& !$this->isClosureUse($tokens, $index, $previousSignificant);
			} elseif ($id === T_FUNCTION) {
				$classBodyDepth = $classBraceDepths === [] ? null : end($classBraceDepths);
				if (($classBodyDepth === null || $classBodyDepth !== $braceDepth)
					&& !$inUseStatement) {
					$name = $this->readDeclarationName($tokens, $index);
					if ($name !== null) {
						$fullName = ltrim($namespace . '\\' . $name, '\\');
						$functions[] = $fullName;
						if (!$this->isFunctionGuarded($fullName, $conditionalFunctionGuards)) {
							$unconditionalFunctions[] = $fullName;
						}
					}
				}
			} elseif ($id === T_CONST && $classBraceDepths === [] && !$inUseStatement) {
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
			$functions,
			array_values(array_unique($constants)),
			$unconditionalFunctions,
		);
	}

	/**
	 * @param list<array{int, string, int}|string> $tokens
	 */
	private function isClosureUse(array $tokens, int $index, int|string|null $previousSignificant) : bool
	{
		if ($previousSignificant !== ')') {
			return false;
		}
		$count = count($tokens);
		for (++$index; $index < $count; ++$index) {
			$token = $tokens[$index];
			if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $token === '(';
		}

		return false;
	}

	/**
	 * @param list<array{int, string, int}|string> $tokens
	 * @return list<string>|null
	 */
	private function conditionFunctionGuardNames(array $tokens, int $index) : ?array
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
		$name = ltrim($this->decodeStringLiteral($rawName, $quote), '\\');
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

	public function decodeStringLiteral(string $raw, string $quote) : string
	{
		$value = substr($raw, 1, -1);
		if ($quote === "'") {
			return str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
		}

		$decoded = '';
		$length = strlen($value);
		for ($index = 0; $index < $length; ++$index) {
			if ($value[$index] !== '\\' || $index + 1 >= $length) {
				$decoded .= $value[$index];
				continue;
			}

			$escape = $value[++$index];
			$decoded .= match ($escape) {
				'\\' => '\\',
				'"' => '"',
				'$' => '$',
				'n' => "\n",
				'r' => "\r",
				't' => "\t",
				'v' => "\v",
				'e' => "\e",
				'f' => "\f",
				default => $this->decodeExtendedStringEscape($value, $index, $escape),
			};
		}

		return $decoded;
	}

	private function decodeExtendedStringEscape(string $value, int &$index, string $escape) : string
	{
		$length = strlen($value);
		if ($escape >= '0' && $escape <= '7') {
			$digits = $escape;
			for ($count = 1; $count < 3 && $index + 1 < $length; ++$count) {
				$next = $value[$index + 1];
				if ($next < '0' || $next > '7') {
					break;
				}
				++$index;
				$digits .= $next;
			}

			return chr($this->parseDigits($digits, 8));
		}
		if ($escape === 'x') {
			$digits = '';
			for ($count = 0; $count < 2 && $index + 1 < $length; ++$count) {
				$next = $value[$index + 1];
				if (preg_match('/^[0-9A-Fa-f]$/', $next) !== 1) {
					break;
				}
				++$index;
				$digits .= $next;
			}
			if ($digits !== '') {
				return chr($this->parseDigits($digits, 16));
			}

			return '\\x';
		}
		if ($escape === 'u' && ($value[$index + 1] ?? '') === '{') {
			$end = strpos($value, '}', $index + 2);
			if ($end !== false) {
				$digits = substr($value, $index + 2, $end - $index - 2);
				if (preg_match('/^[0-9A-Fa-f]+$/', $digits) === 1) {
					$codePoint = $this->parseDigits($digits, 16);
					if ($codePoint <= 0x10FFFF && !($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
						$index = $end;

						return $this->encodeCodePoint($codePoint);
					}
				}
			}
		}

		return '\\' . $escape;
	}

	private function parseDigits(string $digits, int $base) : int
	{
		$value = 0;
		for ($index = 0, $length = strlen($digits); $index < $length; ++$index) {
			$character = ord($digits[$index]);
			$digit = $character <= ord('9')
				? $character - ord('0')
				: 10 + ord(strtolower($digits[$index])) - ord('a');
			$value = ($value * $base) + $digit;
		}

		return $value;
	}

	private function encodeCodePoint(int $codePoint) : string
	{
		if ($codePoint <= 0x7F) {
			return chr($codePoint);
		}
		if ($codePoint <= 0x7FF) {
			return chr(0xC0 | ($codePoint >> 6))
				. chr(0x80 | ($codePoint & 0x3F));
		}
		if ($codePoint <= 0xFFFF) {
			return chr(0xE0 | ($codePoint >> 12))
				. chr(0x80 | (($codePoint >> 6) & 0x3F))
				. chr(0x80 | ($codePoint & 0x3F));
		}

		return chr(0xF0 | ($codePoint >> 18))
			. chr(0x80 | (($codePoint >> 12) & 0x3F))
			. chr(0x80 | (($codePoint >> 6) & 0x3F))
			. chr(0x80 | ($codePoint & 0x3F));
	}

	/**
	 * @param list<array{depth: int, guards: list<string>}> $conditionalFunctionGuards
	 */
	private function isFunctionGuarded(string $function, array $conditionalFunctionGuards) : bool
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
	private function readName(array $tokens, int &$index) : string
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
	private function readDeclarationName(array $tokens, int $index) : ?string
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
	private function readConstantNames(array $tokens, int $index, string $namespace) : array
	{
		$constants = [];
		$expectName = true;
		$squareDepth = 0;
		$parenthesisDepth = 0;
		$braceDepth = 0;
		$count = count($tokens);
		for (++$index; $index < $count; ++$index) {
			$token = $tokens[$index];
			if ($token === ';') {
				break;
			}
			if ($token === '[') {
				++$squareDepth;
				continue;
			}
			if ($token === ']') {
				--$squareDepth;
				continue;
			}
			if ($token === '(') {
				++$parenthesisDepth;
				continue;
			}
			if ($token === ')') {
				--$parenthesisDepth;
				continue;
			}
			if ($token === '{') {
				++$braceDepth;
				continue;
			}
			if ($token === '}') {
				--$braceDepth;
				continue;
			}
			if ($token === ',' && $squareDepth === 0 && $parenthesisDepth === 0 && $braceDepth === 0) {
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
