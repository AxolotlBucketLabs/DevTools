<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionVersionConstraint;
use PHPUnit\Framework\TestCase;
use function str_repeat;

final class VirionVersionConstraintTest extends TestCase
{
	/** @dataProvider matchingConstraintProvider */
	public function test_supported_constraints_match_expected_versions(
		string $constraint,
		string $version,
		bool $expected,
	) : void {
		self::assertSame($expected, (new VirionVersionConstraint($constraint))->matches($version));
	}

	/** @return iterable<string, array{string, string, bool}> */
	public static function matchingConstraintProvider() : iterable
	{
		yield 'any version' => ['*', '9.4.1', true];
		yield 'exact match' => ['1.2.3', '1.2.3', true];
		yield 'exact mismatch' => ['1.2.3', '1.2.4', false];
		yield 'caret stable major' => ['^1.2.3', '1.9.0', true];
		yield 'caret stable next major' => ['^1.2.3', '2.0.0', false];
		yield 'caret zero minor' => ['^0.2.3', '0.2.9', true];
		yield 'caret zero next minor' => ['^0.2.3', '0.3.0', false];
		yield 'tilde same minor' => ['~1.2.3', '1.2.9', true];
		yield 'tilde next minor' => ['~1.2.3', '1.3.0', false];
		yield 'minor wildcard' => ['1.2.*', '1.2.99', true];
		yield 'major wildcard' => ['1.*', '1.99.0', true];
		yield 'major wildcard excludes next-major prerelease' => ['1.*', '2.0.0-alpha', false];
		yield 'caret excludes next-major prerelease' => ['^1.0.0', '2.0.0-alpha', false];
		yield 'minor wildcard excludes next-minor prerelease' => ['1.2.*', '1.3.0-beta', false];
		yield 'tilde excludes next-minor prerelease' => ['~1.2.0', '1.3.0-beta', false];
		yield 'comparison range' => ['>=1.2.0 <2.0.0', '1.8.4', true];
		yield 'comparison range upper bound' => ['>=1.2.0 <2.0.0', '2.0.0', false];
		yield 'different prerelease labels are distinct' => ['1.0.0-alpha.1', '1.0.0-a.1', false];
		yield 'unknown labels use lexical order' => ['>1.0.0-bar.1', '1.0.0-foo.1', true];
		yield 'numeric prerelease precedes lexical' => ['<1.0.0-alpha', '1.0.0-1', true];
		yield 'case-sensitive prerelease labels' => ['1.0.0-alpha', '1.0.0-ALPHA', false];
		yield 'metadata does not affect equality' => ['1.0.0+one', '1.0.0+two', true];
		yield 'large exact versions stay distinct' => ['99999999999999999999.0.0', '99999999999999999998.0.0', false];
		yield 'large prerelease numbers stay distinct' => ['>1.0.0-99999999999999999998', '1.0.0-99999999999999999999', true];
		yield 'large caret lower bound' => ['^99999999999999999999.2.3', '99999999999999999999.2.3', true];
		yield 'large caret upper bound' => ['^99999999999999999999.2.3', '100000000000000000000.0.0', false];
		yield 'large tilde patch' => ['~1.99999999999999999999.0', '1.99999999999999999999.3', true];
		yield 'large wildcard' => ['99999999999999999999.*', '99999999999999999999.0.0', true];
		$commit = '0.0.0-commit.' . str_repeat('a', 39);
		yield 'locked commit match' => [$commit . 'b', $commit . 'b', true];
		yield 'locked commit mismatch despite PHP version comparison' => [$commit . 'b', $commit . 'c', false];
	}

	public function test_unsupported_constraint_is_rejected_with_guidance() : void
	{
		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Use *, an exact version');

		new VirionVersionConstraint('^1.2');
	}

	public function test_locked_commit_does_not_accept_a_range_constraint() : void
	{
		$this->expectException(VirionException::class);
		new VirionVersionConstraint('^0.0.0-commit.' . str_repeat('a', 40));
	}

	public function test_semver_prerelease_precedence_sequence() : void
	{
		$versions = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0'];
		foreach ($versions as $index => $version) {
			if ($index === 0) {
				continue;
			}
			self::assertTrue((new VirionVersionConstraint('>' . $versions[$index - 1]))->matches($version));
		}
	}
}
