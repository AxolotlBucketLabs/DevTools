<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = $root . DIRECTORY_SEPARATOR . '.yamllint';

if (!is_file($config)) {
	fwrite(STDERR, "YAML lint configuration is missing: {$config}\n");
	exit(1);
}

/** @var list<string> $files */
$files = [];
$excludedDirectories = [
	DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
	DIRECTORY_SEPARATOR . '.codex' . DIRECTORY_SEPARATOR,
	DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR,
	DIRECTORY_SEPARATOR . 'graphify-out' . DIRECTORY_SEPARATOR,
	DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
	DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR,
];

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
	/** @var SplFileInfo $file */
	if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['yml', 'yaml'], true)) {
		continue;
	}

	$path = $file->getPathname();
	$excluded = false;
	foreach ($excludedDirectories as $directory) {
		if (str_contains($path, $directory)) {
			$excluded = true;
			break;
		}
	}

	if (!$excluded) {
		$files[] = $path;
	}
}

sort($files, SORT_STRING);

if ($files === []) {
	fwrite(STDERR, "No YAML files were found.\n");
	exit(1);
}

$python = getenv('YAMLLINT_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
/** @var list<string> $command */
$command = [$python, '-m', 'yamllint', '--config-file', $config, ...$files];
$descriptors = [
	0 => ['file', 'php://stdin', 'r'],
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptors, $pipes, $root);
if (!is_resource($process)) {
	fwrite(STDERR, "Unable to start yamllint. Install yamllint 1.38.0 or set YAMLLINT_PYTHON.\n");
	exit(1);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($stdout !== '') {
	fwrite(STDOUT, $stdout);
}
if ($stderr !== '') {
	fwrite(STDERR, $stderr);
}

if ($exitCode === 127) {
	fwrite(STDERR, "yamllint is not installed. Install yamllint 1.38.0 or set YAMLLINT_PYTHON.\n");
}

exit($exitCode);
