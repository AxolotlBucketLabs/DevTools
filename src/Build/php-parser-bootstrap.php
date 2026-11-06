<?php

declare(strict_types=1);

use AxolotlBucketLabs\DevTools\Build\PhpParserNamespaceLoader;

PhpParserNamespaceLoader::register(dirname(__DIR__, 2) . '/vendor/nikic/php-parser/lib/PhpParser');
