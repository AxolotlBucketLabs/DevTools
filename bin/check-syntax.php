<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$roots = ['src', 'bin', 'tests', 'examples'];
$failures = [];
$count = 0;
foreach ($roots as $relativeRoot) {
	$root = $projectRoot . DIRECTORY_SEPARATOR . $relativeRoot;
	if (!is_dir($root)) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
	);
	foreach ($iterator as $entry) {
		if (!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
			continue;
		}
		if (str_ends_with(strtolower($entry->getFilename()), '.tpl.php')) {
			continue;
		}
		$path = str_replace('\\', '/', $entry->getPathname());
		if (str_contains($path, '/vendor/')) {
			continue;
		}
		++$count;
		$source = file_get_contents($entry->getPathname());
		if ($source === false) {
			$failures[] = "Cannot read {$entry->getPathname()}";
			continue;
		}
		try {
			$tokens = token_get_all($source, TOKEN_PARSE);
			unset($tokens);
		} catch (ParseError $error) {
			$failures[] = "{$entry->getPathname()}: {$error->getMessage()}";
		}
	}
}

if ($failures !== []) {
	fwrite(STDERR, "PHP syntax validation failed:\n- " . implode("\n- ", $failures) . "\n");
	exit(1);
}

fwrite(STDOUT, "PHP syntax is valid in {$count} files.\n");
