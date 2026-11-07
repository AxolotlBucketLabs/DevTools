<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$dataPath = $projectRoot . '/docs/ecosystem.json';
$schemaPath = $projectRoot . '/docs/ecosystem.schema.json';

$decode = static function (string $path): array {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException("Cannot read {$path}");
    }

    $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException("Expected a JSON object in {$path}");
    }

    return $value;
};

try {
    $data = $decode($dataPath);
    $schema = $decode($schemaPath);
} catch (JsonException | RuntimeException $exception) {
    fwrite(STDERR, "Ecosystem data validation failed: {$exception->getMessage()}\n");
    exit(1);
}

$failures = [];
/**
 * @param list<string> $keys
 */
$requireKeys = static function (array $value, array $keys, string $path) use (&$failures): void {
    foreach ($keys as $key) {
        if (!is_string($key)) {
            $failures[] = "Invalid key name in {$path}";
            continue;
        }
        if (!array_key_exists($key, $value)) {
            $failures[] = "Missing {$path}.{$key}";
        }
    }
};
$isNonEmptyString = static fn(mixed $value): bool => is_string($value) && $value !== '';

if (($data['$schema'] ?? null) !== './ecosystem.schema.json') {
    $failures[] = 'The data file must reference ./ecosystem.schema.json';
}
if (($data['schema_version'] ?? null) !== 1) {
    $failures[] = 'The data file must use schema version 1';
}
if (($schema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema') {
    $failures[] = 'The schema must use JSON Schema draft 2020-12';
}

$requireKeys($data, ['scope', 'devtools', 'summary', 'verified_repositories', 'migrations'], 'data');
$scope = is_array($data['scope'] ?? null) ? $data['scope'] : [];
$devtools = is_array($data['devtools'] ?? null) ? $data['devtools'] : [];
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$verified = is_array($data['verified_repositories'] ?? null) ? $data['verified_repositories'] : [];
$migrations = is_array($data['migrations'] ?? null) ? $data['migrations'] : [];

$requireKeys(
    $scope,
    ['checked_on', 'description', 'organizations', 'include_archived', 'repositories_inspected', 'workflow_files_inspected', 'counting_rule', 'exclusions'],
    'data.scope',
);
$requireKeys($devtools, ['stable_version', 'stable_commit', 'reference_policy'], 'data.devtools');
$requireKeys($summary, ['verified_repositories', 'migrated_repositories', 'total_repositories', 'organization_totals'], 'data.summary');

if (!is_string($scope['checked_on'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $scope['checked_on']) !== 1) {
    $failures[] = 'data.scope.checked_on must use YYYY-MM-DD';
}
if (($scope['include_archived'] ?? null) !== false) {
    $failures[] = 'data.scope.include_archived must be false';
}
if (!is_array($scope['organizations'] ?? null) || $scope['organizations'] === []) {
    $failures[] = 'data.scope.organizations must be a non-empty array';
}
if (!is_string($devtools['stable_commit'] ?? null) || preg_match('/^[0-9a-f]{40}$/', $devtools['stable_commit']) !== 1) {
    $failures[] = 'data.devtools.stable_commit must be a lowercase 40-character commit';
}

$organizationKeys = [];
$organizations = is_array($scope['organizations'] ?? null) ? $scope['organizations'] : [];
foreach ($organizations as $organization) {
    if (!is_string($organization) || $organization === '') {
        $failures[] = 'Every organization name must be a non-empty string';
        continue;
    }
    $organizationKeys[$organization] = true;
}

$readRepositories = static function (array $records, string $group) use (&$failures, $isNonEmptyString): array {
    $keys = [];
    foreach ($records as $index => $record) {
        $path = "data.{$group}[{$index}]";
        if (!is_array($record)) {
            $failures[] = "{$path} must be an object";
            continue;
        }
        foreach (['organization', 'name', 'url'] as $key) {
            if (!$isNonEmptyString($record[$key] ?? null)) {
                $failures[] = "{$path}.{$key} must be a non-empty string";
            }
        }
        $organization = $record['organization'] ?? null;
        $name = $record['name'] ?? null;
        $url = $record['url'] ?? null;
        if (!is_string($organization) || !is_string($name) || !is_string($url)) {
            continue;
        }
        $expectedUrl = "https://github.com/{$organization}/{$name}";
        if ($url !== $expectedUrl) {
            $failures[] = "{$path}.url must match its organization and name";
        }
        $key = "{$organization}/{$name}";
        if (isset($keys[$key])) {
            $failures[] = "Duplicate repository {$key} in {$group}";
        }
        $keys[$key] = true;
    }

    return $keys;
};

$verifiedKeys = $readRepositories($verified, 'verified_repositories');
$migrationKeys = $readRepositories($migrations, 'migrations');
foreach (array_intersect_key($verifiedKeys, $migrationKeys) as $key => $_) {
    $failures[] = "Repository {$key} appears in both verified_repositories and migrations";
}

$verifiedCount = count($verified);
$migrationCount = count($migrations);
if (($summary['verified_repositories'] ?? null) !== $verifiedCount) {
    $failures[] = 'The verified repository summary does not match the data';
}
if (($summary['migrated_repositories'] ?? null) !== $migrationCount) {
    $failures[] = 'The migration summary does not match the data';
}
if (($summary['total_repositories'] ?? null) !== $verifiedCount + $migrationCount) {
    $failures[] = 'The total repository summary does not match the data';
}

$totals = is_array($summary['organization_totals'] ?? null) ? $summary['organization_totals'] : [];
$totalOrganizations = [];
foreach ($totals as $index => $total) {
    $path = "data.summary.organization_totals[{$index}]";
    if (!is_array($total)) {
        $failures[] = "{$path} must be an object";
        continue;
    }
    $requireKeys($total, ['organization', 'verified', 'migrated', 'total'], $path);
    $organization = $total['organization'] ?? null;
    if (!is_string($organization) || $organization === '') {
        $failures[] = "{$path}.organization must be a non-empty string";
        continue;
    }
    $totalOrganizations[$organization] = true;
    $verifiedForOrganization = count(array_filter($verified, static fn(mixed $record): bool => is_array($record) && ($record['organization'] ?? null) === $organization));
    $migratedForOrganization = count(array_filter($migrations, static fn(mixed $record): bool => is_array($record) && ($record['organization'] ?? null) === $organization));
    if (($total['verified'] ?? null) !== $verifiedForOrganization
        || ($total['migrated'] ?? null) !== $migratedForOrganization
        || ($total['total'] ?? null) !== $verifiedForOrganization + $migratedForOrganization) {
        $failures[] = "The organization summary does not match {$organization}";
    }
}
if (array_diff_key($organizationKeys, $totalOrganizations) !== [] || array_diff_key($totalOrganizations, $organizationKeys) !== []) {
    $failures[] = 'The organization list and organization totals must contain the same names';
}

if ($failures !== []) {
    fwrite(STDERR, "Ecosystem data validation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Ecosystem data is valid ({$verifiedCount} verified, {$migrationCount} migrated, " . ($verifiedCount + $migrationCount) . " total repositories).\n");
