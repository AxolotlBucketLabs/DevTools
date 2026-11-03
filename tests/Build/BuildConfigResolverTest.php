<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Tests\Build;

use NhanAZ\DevTools\Build\BuildConfigResolver;
use NhanAZ\DevTools\Build\BuildException;
use NhanAZ\DevTools\Support\YamlReader;
use NhanAZ\DevTools\Tests\TestCase;

final class BuildConfigResolverTest extends TestCase
{
    public function test_case_conflicting_include_paths_are_rejected(): void
    {
        file_put_contents(
            $this->temporaryDirectory . '/devtools.yml',
            "include-paths:\n  - Assets\n  - assets\n",
        );

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('case-conflicting');

        (new BuildConfigResolver(new YamlReader()))->resolve($this->temporaryDirectory, 'Fixture');
    }
}
