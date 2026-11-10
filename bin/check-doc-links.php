<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$markdownFiles = [];
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $entry) {
	if (!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'md') {
		continue;
	}
	$path = str_replace('\\', '/', $entry->getPathname());
	if (str_contains($path, '/vendor/') || str_contains($path, '/build/') || str_contains($path, '/graphify-out/')
		|| str_contains($path, '/.git/') || str_contains($path, '/.devtools-dependencies/')
		|| str_contains($path, '/.codex/skills/graphify/')) {
		continue;
	}
	$markdownFiles[] = $entry->getPathname();
}

$failures = [];
$checked = 0;
foreach ($markdownFiles as $markdownFile) {
	$contents = file_get_contents($markdownFile);
	if (!is_string($contents)) {
		$failures[] = "Cannot read {$markdownFile}";
		continue;
	}
	if (preg_match('/\x{201C}|\x{201D}/u', $contents) === 1) {
		$failures[] = "{$markdownFile} uses typographic double quotes. Use ASCII double quotes instead";
	}
	if (preg_match('/\x{2013}|\x{2014}/u', $contents) === 1) {
		$failures[] = "{$markdownFile} uses an en dash or em dash. Use an ASCII hyphen instead";
	}
	preg_match_all('/!?\[[^\]]*]\((?<target>[^)]+)\)/', $contents, $matches);
	foreach ($matches['target'] as $target) {
		$target = trim($target, " \t\n\r\0\x0B<>");
		if ($target === '' || str_starts_with($target, '#') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $target) === 1) {
			continue;
		}
		$target = explode('#', explode('?', $target, 2)[0], 2)[0];
		$resolved = dirname($markdownFile) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($target));
		++$checked;
		if (!file_exists($resolved)) {
			$failures[] = "{$markdownFile} references missing path {$target}";
		}
	}
}

if ($failures !== []) {
	fwrite(STDERR, "Documentation link validation failed:\n- " . implode("\n- ", $failures) . "\n");
	exit(1);
}

fwrite(STDOUT, "Documentation links are valid ({$checked} local targets across " . count($markdownFiles) . " Markdown files).\n");
