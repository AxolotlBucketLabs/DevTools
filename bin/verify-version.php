<?php

declare(strict_types=1);

use AxolotlBucketLabs\DevTools\Virion\VirionVersionConstraint;

require dirname(__DIR__) . '/vendor/autoload.php';

const RELEASE_TAG_PREFIX = 'v';

$expected = $argv[1] ?? '';
if (str_starts_with($expected, RELEASE_TAG_PREFIX)) {
	$expected = substr($expected, strlen(RELEASE_TAG_PREFIX));
}
if (!VirionVersionConstraint::isValidVersion($expected)) {
	fwrite(STDERR, "Expected a semantic release version or v-prefixed tag. Received \"{$expected}\".\n");
	exit(1);
}
$projectRoot = dirname(__DIR__);
$manifest = yaml_parse_file($projectRoot . DIRECTORY_SEPARATOR . 'plugin.yml');
if (!is_array($manifest) || ($manifest['version'] ?? null) !== $expected) {
	fwrite(STDERR, "plugin.yml does not declare version {$expected}.\n");
	exit(1);
}
$composerContents = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json');
$composer = is_string($composerContents) ? json_decode($composerContents, true) : null;
$extra = is_array($composer) ? ($composer['extra'] ?? null) : null;
$devtools = is_array($extra) ? ($extra['devtools'] ?? null) : null;
if (!is_array($devtools) || ($devtools['release-version'] ?? null) !== $expected) {
	fwrite(STDERR, "composer.json extra.devtools.release-version does not declare {$expected}.\n");
	exit(1);
}
$changelog = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'CHANGELOG.md');
if (!is_string($changelog) || !str_contains($changelog, "## {$expected} -")) {
	fwrite(STDERR, "CHANGELOG.md has no dated section for {$expected}.\n");
	exit(1);
}
$releaseNotes = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'RELEASE_NOTES.md');
if (!is_string($releaseNotes)
	|| preg_match('/^# DevTools ' . preg_quote($expected, '/') . '[ \t]*$/m', $releaseNotes) !== 1) {
	fwrite(STDERR, "RELEASE_NOTES.md does not describe DevTools {$expected}.\n");
	exit(1);
}

fwrite(STDOUT, "Release version {$expected} is synchronized across the tag input, composer.json, plugin.yml, CHANGELOG.md, and RELEASE_NOTES.md.\n");
