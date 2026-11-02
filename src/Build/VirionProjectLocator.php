<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function count;
use function dirname;
use function implode;
use function is_dir;

use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Virion\VirionDiscovery;
use NhanAZ\DevTools\Virion\VirionProjectFactory;

use function realpath;
use function strtolower;

use Throwable;

final class VirionProjectLocator
{
    public function __construct(
        private readonly VirionDiscovery $discovery,
        private readonly VirionProjectFactory $factory,
    ) {}

    public function locate(string $nameOrPath, string $virionsDirectory): string
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
            if ($real !== false && Path::isInside($virionsRoot, $real) && dirname($real) === $virionsRoot) {
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
