<?php

declare(strict_types=1);

function artifactFailure(string $message) : never
{
	fwrite(STDERR, "Artifact validation failed: {$message}\n");
	exit(1);
}

$projectRoot = dirname(__DIR__);
$path = $argv[1] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'DevTools.phar');
if (!is_file($path)) {
	artifactFailure("PHAR does not exist: {$path}");
}
$size = filesize($path);
if ($size === false || $size < 1024) {
	artifactFailure("PHAR is unexpectedly small: {$path}");
}

try {
	$phar = new Phar($path);
} catch (Throwable $error) {
	artifactFailure("Cannot open {$path}: {$error->getMessage()}");
}
foreach (['plugin.yml', 'src/DevTools.php', 'vendor/nikic/php-parser/lib/PhpParser/Parser.php', 'vendor/nikic/php-parser/LICENSE'] as $required) {
	if (!isset($phar[$required])) {
		artifactFailure("required entry {$required} is missing");
	}
}

$manifest = yaml_parse($phar['plugin.yml']->getContent());
if (!is_array($manifest)) {
	artifactFailure('plugin.yml is not a YAML map');
}
$expectedVersion = $argv[2] ?? null;
if (is_string($expectedVersion)) {
	if (str_starts_with($expectedVersion, 'v')) {
		$expectedVersion = substr($expectedVersion, 1);
	}
	if (($manifest['version'] ?? null) !== $expectedVersion) {
		artifactFailure("plugin.yml version does not match {$expectedVersion}");
	}
}
if (($manifest['name'] ?? null) !== 'DevTools'
	|| ($manifest['main'] ?? null) !== 'AxolotlBucketLabs\\DevTools\\DevTools'
	|| ($manifest['api'] ?? null) !== '5.0.0') {
	artifactFailure('plugin.yml identity, main class, or API is incorrect');
}

$forbiddenPathPatterns = [
	'/^(?:\.git|\.github|\.idea|\.vscode|tests?|build|cache|logs?)(?:\/|$)/i',
	'/^(?:\.env(?:\..*)?|auth\.json|composer\.(?:json|lock)|phpunit\.xml(?:\.dist)?|phpstan\.neon(?:\.dist)?)(?:$|\/)/i',
];
$secretPatterns = [
	'/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/',
	'/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
	'/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
	'/\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b/',
];
$fileCount = 0;
$iterator = new RecursiveIteratorIterator($phar);
foreach ($iterator as $entry) {
	if (!$entry instanceof PharFileInfo || !$entry->isFile()) {
		continue;
	}
	++$fileCount;
	if ($entry->isLink()) {
		artifactFailure("symbolic link found: {$entry->getPathName()}");
	}
	$entryPath = $entry->getPathName();
	$prefix = 'phar://' . $phar->getPath() . '/';
	if (str_starts_with($entryPath, $prefix)) {
		$entryPath = substr($entryPath, strlen($prefix));
	}
	if (str_starts_with(strtolower($entryPath), 'vendor/')
		&& !str_starts_with($entryPath, 'vendor/nikic/php-parser/lib/')
		&& $entryPath !== 'vendor/nikic/php-parser/LICENSE') {
		artifactFailure("unapproved bundled dependency path: {$entryPath}. Only nikic/php-parser source and license belong in DevTools.phar");
	}
	foreach ($forbiddenPathPatterns as $pattern) {
		if (preg_match($pattern, $entryPath) === 1) {
			artifactFailure("development or sensitive path was packaged: {$entryPath}");
		}
	}
	$contents = $entry->getContent();
	foreach ($secretPatterns as $pattern) {
		if (preg_match($pattern, $contents) === 1) {
			artifactFailure("possible secret found in {$entryPath}");
		}
	}
}

/** @var array{hash: string, hash_type: string}|false $signature */
$signature = $phar->getSignature();
if ($signature === false || $signature['hash_type'] !== 'SHA-256') {
	artifactFailure('PHAR signature is not SHA-256');
}
$checksum = hash_file('sha256', $path);
if (!is_string($checksum)) {
	artifactFailure('cannot calculate SHA-256 checksum');
}

fwrite(STDOUT, "Artifact is valid: {$path} ({$fileCount} files, {$size} bytes, SHA-256 {$checksum}).\n");
