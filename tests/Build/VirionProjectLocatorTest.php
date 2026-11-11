<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\BuildException;
use AxolotlBucketLabs\DevTools\Build\VirionProjectLocator;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use const DIRECTORY_SEPARATOR;

final class VirionProjectLocatorTest extends TestCase
{
	public function test_direct_path_outside_virions_is_rejected_explicitly() : void
	{
		$virions = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'virions';
		$this->filesystem->ensureDirectory($virions);
		$outside = $this->copyFixture('SharedVirion', 'outside/SharedVirion');

		$this->expectException(BuildException::class);
		$this->expectExceptionMessage('outside the immediate virions directory');

		$this->locator()->locate($outside, $virions);
	}

	private function locator() : VirionProjectLocator
	{
		return new VirionProjectLocator(
			new VirionDiscovery($this->filesystem),
			new VirionProjectFactory(new VirionManifestReader(new YamlReader())),
		);
	}
}
