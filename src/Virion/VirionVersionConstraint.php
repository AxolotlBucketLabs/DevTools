<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use function count;
use function explode;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_contains;
use function strcmp;
use function strlen;
use function strtolower;
use function substr;
use function trim;

final class VirionVersionConstraint
{
	private const COMMIT_REFERENCE_LENGTH = 40;
	private const VERSION_PATTERN = '(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?';
	private const COMMIT_VERSION_PATTERN = '/^0\.0\.0-commit\.[a-fA-F0-9]{' . self::COMMIT_REFERENCE_LENGTH . '}$/D';

	/** @var list<string> */
	private readonly array $tokens;

	public readonly string $expression;

	public function __construct(string $expression)
	{
		$expression = trim($expression);
		if ($expression === '') {
			throw new VirionException('A virion version constraint may not be empty. Use * for any version.');
		}

		$tokens = preg_split('/\s+/', $expression) ?: [];
		foreach ($tokens as $token) {
			if (!$this->isValidToken($token)) {
				throw new VirionException(
					"Unsupported virion version constraint \"{$expression}\". "
					. 'Use *, an exact version, ^1.2.3, ~1.2.3, 1.2.*, or comparisons such as >=1.2.0 <2.0.0.',
				);
			}
		}
		$this->expression = $expression;
		$this->tokens = $tokens;
	}

	public static function isValidVersion(string $version) : bool
	{
		return preg_match('/^' . self::VERSION_PATTERN . '$/D', $version) === 1;
	}

	public function matches(string $version) : bool
	{
		if (!self::isValidVersion($version)) {
			return false;
		}
		foreach ($this->tokens as $token) {
			if (!$this->tokenMatches($version, $token)) {
				return false;
			}
		}

		return true;
	}

	public static function compareVersions(string $left, string $right) : int
	{
		$leftParts = explode('-', explode('+', $left, 2)[0], 2);
		$rightParts = explode('-', explode('+', $right, 2)[0], 2);
		$rightCore = explode('.', $rightParts[0]);
		foreach (explode('.', $leftParts[0]) as $index => $number) {
			$comparison = self::compareNumbers($number, $rightCore[$index]);
			if ($comparison !== 0) {
				return $comparison;
			}
		}
		if (!isset($leftParts[1]) || !isset($rightParts[1])) {
			return isset($rightParts[1]) <=> isset($leftParts[1]);
		}
		$leftIdentifiers = explode('.', $leftParts[1]);
		$rightIdentifiers = explode('.', $rightParts[1]);
		foreach ($leftIdentifiers as $index => $identifier) {
			if (!isset($rightIdentifiers[$index])) {
				return 1;
			}
			$other = $rightIdentifiers[$index];
			$leftNumeric = preg_match('/^[0-9]+$/D', $identifier) === 1;
			$rightNumeric = preg_match('/^[0-9]+$/D', $other) === 1;
			$comparison = $leftNumeric && $rightNumeric
				? self::compareNumbers($identifier, $other)
				: ($leftNumeric !== $rightNumeric ? $rightNumeric <=> $leftNumeric : strcmp($identifier, $other) <=> 0);
			if ($comparison !== 0) {
				return $comparison;
			}
		}
		return count($leftIdentifiers) <=> count($rightIdentifiers);
	}

	private static function compareNumbers(string $left, string $right) : int
	{
		$left = ltrim($left, '0') ?: '0';
		$right = ltrim($right, '0') ?: '0';
		return (strlen($left) <=> strlen($right)) ?: (strcmp($left, $right) <=> 0);
	}

	private function isValidToken(string $token) : bool
	{
		if (str_contains(strtolower($token), '0.0.0-commit.')) {
			return preg_match(self::COMMIT_VERSION_PATTERN, $token) === 1;
		}
		return $token === '*'
			|| preg_match('/^(?:\^|~|<=|>=|<|>|=)?' . self::VERSION_PATTERN . '$/D', $token) === 1
			|| preg_match('/^(?:0|[1-9][0-9]*)\.\*$/D', $token) === 1
			|| preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.\*$/D', $token) === 1;
	}

	private function tokenMatches(string $version, string $token) : bool
	{
		if ($token === '*') {
			return true;
		}
		if (preg_match(self::COMMIT_VERSION_PATTERN, $version) === 1 || preg_match(self::COMMIT_VERSION_PATTERN, $token) === 1) {
			return strtolower($version) === strtolower($token);
		}
		if (preg_match('/^(?<major>0|[1-9][0-9]*)\.\*$/D', $token, $match) === 1) {
			$minimum = $match['major'] . '.0.0';
			$maximum = self::increment($match['major']) . '.0.0';

			return $this->between($version, $minimum, $maximum);
		}
		if (preg_match('/^(?<major>0|[1-9][0-9]*)\.(?<minor>0|[1-9][0-9]*)\.\*$/D', $token, $match) === 1) {
			$minimum = $match['major'] . '.' . $match['minor'] . '.0';
			$maximum = $match['major'] . '.' . self::increment($match['minor']) . '.0';

			return $this->between($version, $minimum, $maximum);
		}
		if ($token[0] === '^' || $token[0] === '~') {
			$minimum = substr($token, 1);
			[$major, $minor, $patch] = $this->numericParts($minimum);
			if ($token[0] === '~') {
				$maximum = $major . '.' . self::increment($minor) . '.0';
			} elseif ($major !== '0') {
				$maximum = self::increment($major) . '.0.0';
			} elseif ($minor !== '0') {
				$maximum = '0.' . self::increment($minor) . '.0';
			} else {
				$maximum = '0.0.' . self::increment($patch);
			}

			return $this->between($version, $minimum, $maximum);
		}
		if (preg_match('/^(?<operator><=|>=|<|>|=)(?<version>.+)$/D', $token, $match) === 1) {
			$comparison = self::compareVersions($version, $match['version']);
			return match ($match['operator']) {
				'<' => $comparison < 0,
				'<=' => $comparison <= 0,
				'>' => $comparison > 0,
				'>=' => $comparison >= 0,
				'=' => $comparison === 0,
			};
		}

		return self::compareVersions($version, $token) === 0;
	}

	private function between(string $version, string $minimum, string $maximum) : bool
	{
		if (self::compareVersions($version, $minimum) < 0 || self::compareVersions($version, $maximum) >= 0) {
			return false;
		}

		return !($this->isPrerelease($version) && $this->numericCore($version) === $maximum);
	}

	private function isPrerelease(string $version) : bool
	{
		return str_contains(explode('+', $version, 2)[0], '-');
	}

	private function numericCore(string $version) : string
	{
		return explode('-', explode('+', $version, 2)[0], 2)[0];
	}

	/** @return array{string, string, string} */
	private function numericParts(string $version) : array
	{
		$core = explode('-', explode('+', $version, 2)[0], 2)[0];
		[$major, $minor, $patch] = explode('.', $core);

		return [$major, $minor, $patch];
	}

	private static function increment(string $number) : string
	{
		for ($index = strlen($number) - 1; $index >= 0; --$index) {
			if ($number[$index] !== '9') {
				$number[$index] = (string) ((int) $number[$index] + 1);
				return $number;
			}
			$number[$index] = '0';
		}
		return '1' . $number;
	}
}
