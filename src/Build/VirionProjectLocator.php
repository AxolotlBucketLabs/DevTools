<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\Filesystem;
use AxolotlBucketLabs\DevTools\Support\Path;
use AxolotlBucketLabs\DevTools\Virion\VirionDiscovery;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use Throwable;
use function count;
use function dirname;
use function implode;
use function is_dir;
use function realpath;
use function str_contains;
use function strtolower;

final class VirionProjectLocator
{
	public function __construct(
		private readonly VirionDiscovery $discovery,
		private readonly VirionProjectFactory $factory,
	) {}

	public function locate(string $nameOrPath, string $virionsDirectory) : string
	{
		if (str_contains($nameOrPath, "\0")) {
			throw new BuildException('Virion selection contains a null byte.');
		}
		$virionsRoot = realpath($virionsDirectory);
		if ($virionsRoot === false) {
			throw new BuildException("Cannot resolve virions directory {$virionsDirectory}.");
		}
		if (is_dir($nameOrPath)) {
			if (Filesystem::isLink($nameOrPath)) {
				throw new BuildException("Refusing symbolic-link virion project: {$nameOrPath}");
			}
			$real = realpath($nameOrPath);
			if ($real !== false) {
				if (!Path::isInside($virionsRoot, $real) || dirname($real) !== $virionsRoot) {
					throw new BuildException(
						"Refusing virion project outside the immediate virions directory {$virionsRoot}: {$real}",
					);
				}

				return $real;
			}
		}

		$matches = [];
		foreach ($this->discovery->discover($virionsDirectory) as $location) {
			try {
				$project = $this->factory->open($location);
			} catch (Throwable) {
				continue;
			}
			if (!$project->phar && strtolower($project->manifest->name) === strtolower($nameOrPath)) {
				$matches[] = $location;
			}
		}
		if (count($matches) === 1) {
			return $matches[0];
		}
		if ($matches !== []) {
			throw new BuildException('Virion name "' . $nameOrPath . '" is ambiguous: ' . implode(', ', $matches) . '. Select an explicit source folder.');
		}

		throw new BuildException("Cannot find virion source \"{$nameOrPath}\" under {$virionsDirectory}.");
	}
}
