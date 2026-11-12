import { appendFileSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const inventoryPath = resolve(root, 'docs/ecosystem.json');
const compatibilityPath = resolve(root, 'docs/ecosystem-compatibility.json');
const inventory = JSON.parse(readFileSync(inventoryPath, 'utf8'));
const compatibility = JSON.parse(readFileSync(compatibilityPath, 'utf8'));

if (compatibility.schema_version !== 1 || !Array.isArray(compatibility.projects)) {
	throw new Error('Unsupported ecosystem compatibility configuration.');
}

const inventoryRepositories = new Set(inventory.repositories.map(({ organization, name }) => `${organization}/${name}`));
const configuredRepositories = new Set();
const supportedTypes = new Set(['plugin', 'virion']);
const matrix = [];

for (const project of compatibility.projects) {
	const { repository, project: projectPath, roles, prepare_dependencies: prepareDependencies } = project;
	if (typeof repository !== 'string' || !inventoryRepositories.has(repository)) {
		throw new Error(`Unexpected repository in compatibility configuration: ${String(repository)}`);
	}
	if (configuredRepositories.has(repository)) {
		throw new Error(`Duplicate compatibility configuration for ${repository}.`);
	}
	configuredRepositories.add(repository);
	if (typeof projectPath !== 'string' || projectPath === '' || projectPath.startsWith('/') || projectPath.split(/[\\/]/).includes('..')) {
		throw new Error(`Invalid project path for ${repository}.`);
	}
	if (!Array.isArray(roles) || roles.length === 0 || roles.some((role) => !supportedTypes.has(role)) || new Set(roles).size !== roles.length) {
		throw new Error(`Invalid build roles for ${repository}.`);
	}
	if (typeof prepareDependencies !== 'boolean' || (prepareDependencies && !roles.includes('plugin'))) {
		throw new Error(`Invalid Composer preparation setting for ${repository}.`);
	}

	for (const type of roles) {
		matrix.push({
			repository,
			project: projectPath,
			type,
			expected_roles: roles.join(','),
			prepare_dependencies: type === 'plugin' && prepareDependencies ? 'true' : 'false',
		});
	}
}

for (const repository of inventoryRepositories) {
	if (!configuredRepositories.has(repository)) {
		throw new Error(`Missing compatibility configuration for ${repository}.`);
	}
}

if (configuredRepositories.size !== inventoryRepositories.size) {
	throw new Error('The compatibility configuration does not match the ecosystem inventory.');
}

if (process.env.GITHUB_OUTPUT === undefined) {
	throw new Error('GITHUB_OUTPUT is required.');
}

appendFileSync(process.env.GITHUB_OUTPUT, `matrix=${JSON.stringify(matrix)}\n`);
console.log(`Prepared ${matrix.length} role-specific builds across ${configuredRepositories.size} ecosystem repositories.`);
