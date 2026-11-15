<?php

declare(strict_types=1);

const ECOSYSTEM_DATA_PATH = 'docs/ecosystem.json';
const ECOSYSTEM_RELEASE_METADATA_KEY = 'devtools';
const ECOSYSTEM_STABLE_VERSION_KEY = 'stable_version';
const ECOSYSTEM_STABLE_COMMIT_KEY = 'stable_commit';
const STABLE_RELEASE_TAG_PATTERN = '/^v(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/';
const GIT_COMMIT_PATTERN = '/^[a-f0-9]{40}$/';

$projectRoot = dirname(__DIR__);

/**
 * @param non-empty-list<string> $command
 */
function runEcosystemReleaseGitCommand(array $command, string $projectRoot) : string {
	$process = proc_open(
		$command,
		[
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		],
		$pipes,
		$projectRoot,
	);
	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start Git.');
	}

	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	if ($exitCode !== 0 || !is_string($output) || !is_string($error)) {
		$message = is_string($error) ? trim($error) : '';
		throw new RuntimeException($message !== '' ? $message : 'Git could not read the release tags.');
	}

	return trim($output);
}

$replaceJsonString = static function (string $contents, string $key, string $value) : string {
	$pattern = '/(^[ \t]*"' . preg_quote($key, '/') . '"[ \t]*:[ \t]*)"[^"\r\n]*"/m';
	$updated = preg_replace_callback(
		$pattern,
		static fn(array $matches) : string => $matches[1] . json_encode($value, JSON_THROW_ON_ERROR),
		$contents,
		1,
		$count,
	);
	if (!is_string($updated) || $count !== 1) {
		throw new RuntimeException("Expected exactly one {$key} field in the ecosystem data.");
	}

	return $updated;
};

try {
	$tags = array_values(array_filter(
		preg_split('/\R/', runEcosystemReleaseGitCommand(['git', 'tag', '--list'], $projectRoot)) ?: [],
		static fn(string $tag) : bool => preg_match(STABLE_RELEASE_TAG_PATTERN, $tag) === 1,
	));
	if ($tags === []) {
		throw new RuntimeException('No stable vX.Y.Z Git tags are available. Fetch the repository tags and retry.');
	}

	usort($tags, static fn(string $left, string $right) : int => version_compare(substr($right, 1), substr($left, 1)));
	$stableTag = $tags[0];
	$stableCommit = runEcosystemReleaseGitCommand(['git', 'rev-parse', '--verify', "refs/tags/{$stableTag}^{commit}"], $projectRoot);
	if (preg_match(GIT_COMMIT_PATTERN, $stableCommit) !== 1) {
		throw new RuntimeException("Git did not resolve {$stableTag} to a full commit SHA.");
	}

	$path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ECOSYSTEM_DATA_PATH);
	$contents = file_get_contents($path);
	if (!is_string($contents)) {
		throw new RuntimeException('Cannot read the ecosystem data file.');
	}
	$data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($data) || !is_array($data[ECOSYSTEM_RELEASE_METADATA_KEY] ?? null)) {
		throw new RuntimeException('The ecosystem data does not contain release metadata.');
	}

	$metadata = $data[ECOSYSTEM_RELEASE_METADATA_KEY];
	if (($metadata[ECOSYSTEM_STABLE_VERSION_KEY] ?? null) === $stableTag
		&& ($metadata[ECOSYSTEM_STABLE_COMMIT_KEY] ?? null) === $stableCommit) {
		fwrite(STDOUT, "Ecosystem release metadata already matches {$stableTag}.\n");
		exit(0);
	}

	$updated = $replaceJsonString($contents, ECOSYSTEM_STABLE_VERSION_KEY, $stableTag);
	$updated = $replaceJsonString($updated, ECOSYSTEM_STABLE_COMMIT_KEY, $stableCommit);
	$updatedData = json_decode($updated, true, 512, JSON_THROW_ON_ERROR);
	$updatedMetadata = is_array($updatedData) ? ($updatedData[ECOSYSTEM_RELEASE_METADATA_KEY] ?? null) : null;
	if (!is_array($updatedMetadata)
		|| ($updatedMetadata[ECOSYSTEM_STABLE_VERSION_KEY] ?? null) !== $stableTag
		|| ($updatedMetadata[ECOSYSTEM_STABLE_COMMIT_KEY] ?? null) !== $stableCommit) {
		throw new RuntimeException('The updated ecosystem release metadata could not be verified.');
	}

	if (file_put_contents($path, $updated, LOCK_EX) === false) {
		throw new RuntimeException('Cannot write the ecosystem data file.');
	}
	fwrite(STDOUT, "Updated ecosystem release metadata to {$stableTag} at {$stableCommit}.\n");
} catch (JsonException | RuntimeException $exception) {
	fwrite(STDERR, "Ecosystem release metadata sync failed: {$exception->getMessage()}\n");
	exit(1);
}
