<?php

declare(strict_types=1);

const MINIMUM_PHAR_SIZE_BYTES = 1024;
const PHAR_SHA256_SIGNATURE = 'SHA-256';
const SHA256_ALGORITHM = 'sha256';
const RELEASE_TAG_PREFIX = 'v';
const YAML_PHP_OBJECT_DECODING_INI = 'yaml.decode_php';
const YAML_PHP_OBJECT_DECODING_DISABLED = '0';

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
if ($size === false || $size < MINIMUM_PHAR_SIZE_BYTES) {
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

if (ini_set(YAML_PHP_OBJECT_DECODING_INI, YAML_PHP_OBJECT_DECODING_DISABLED) === false) {
	artifactFailure('Cannot disable PHP object decoding for YAML parsing');
}
$manifest = yaml_parse($phar['plugin.yml']->getContent());
if (!is_array($manifest)) {
	artifactFailure('plugin.yml is not a YAML map');
}
$expectedVersion = $argv[2] ?? null;
if (is_string($expectedVersion)) {
	if (str_starts_with($expectedVersion, RELEASE_TAG_PREFIX)) {
		$expectedVersion = substr($expectedVersion, strlen(RELEASE_TAG_PREFIX));
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
if ($signature === false || $signature['hash_type'] !== PHAR_SHA256_SIGNATURE) {
	artifactFailure('PHAR signature is not SHA-256');
}
$checksum = hash_file(SHA256_ALGORITHM, $path);
if (!is_string($checksum)) {
	artifactFailure('cannot calculate SHA-256 checksum');
}

fwrite(STDOUT, "Artifact is valid: {$path} ({$fileCount} files, {$size} bytes, SHA-256 {$checksum}).\n");
