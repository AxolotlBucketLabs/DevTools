<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionPlatformRequirements;
use function extension_loaded;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;

final class VirionPlatformRequirementsTest extends TestCase
{
	public function test_current_php_and_available_extension_satisfy_bounded_composer_constraints() : void
	{
		(new VirionPlatformRequirements())->validate(['php' => '^7.4 || >=8.1 <9.0', 'php-64bit' => '*', 'ext-json' => '*'], 'Example');
		$this->expectNotToPerformAssertions();
	}

	public function test_future_php_requirement_is_not_relabelled_as_php_81() : void
	{
		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('requires PHP ^99.0');
		(new VirionPlatformRequirements())->validate(['php' => '^99.0'], 'Example');
	}

	public function test_unknown_constraint_is_rejected_instead_of_silently_ignored() : void
	{
		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('Unsupported Composer PHP constraint');
		(new VirionPlatformRequirements())->validate(['php' => '^8.1 || ~8.2'], 'Example');
	}

	public function test_composer_php_minor_wildcard_matches_current_php_minor() : void
	{
		(new VirionPlatformRequirements())->validate(
			['php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.*'],
			'Example',
		);
		$this->expectNotToPerformAssertions();
	}

	public function test_composer_php_wildcard_rejects_a_different_minor() : void
	{
		$nextMinor = PHP_MINOR_VERSION + 1;
		$this->expectException(VirionException::class);
		$this->expectExceptionMessage('requires PHP ' . PHP_MAJOR_VERSION . '.' . $nextMinor . '.*');
		(new VirionPlatformRequirements())->validate(['php' => PHP_MAJOR_VERSION . '.' . $nextMinor . '.*'], 'Example');
	}

	public function test_composer_normalized_zend_opcache_extension_name_is_recognized() : void
	{
		if (!extension_loaded('Zend OPcache')) {
			self::markTestSkipped('Zend OPcache is not loaded in this PHP runtime.');
		}
		(new VirionPlatformRequirements())->validate(['ext-zend-opcache' => '*'], 'Example');
		$this->expectNotToPerformAssertions();
	}
}
