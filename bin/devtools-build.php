<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cwd = getcwd();
if ($cwd === false) {
	fwrite(STDERR, "Cannot determine the working directory.\n");
	exit(1);
}
$legacyDefaults = ['project' => $root, 'out' => $root . '/build', 'virions' => dirname($root, 2) . '/virions'];
$arguments = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
	if (preg_match('/^--(project|out|virions)=(.+)$/Ds', $argument, $match) === 1) {
		$path = $match[2];
		if (!str_starts_with($path, '/') && !str_starts_with($path, '\\') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
			$path = $cwd . DIRECTORY_SEPARATOR . $path;
		}
		unset($legacyDefaults[$match[1]]);
		$argument = '--' . $match[1] . '=' . $path;
	}
	$arguments[] = $argument;
}
$argv = [__FILE__, 'build'];
foreach ($legacyDefaults as $key => $value) {
	$argv[] = '--' . $key . '=' . $value;
}
$argv = array_merge($argv, $arguments);
require __DIR__ . '/devtools.php';
