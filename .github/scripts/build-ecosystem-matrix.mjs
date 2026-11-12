import { appendFileSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

function isSafeRelativePath(value) {
	return typeof value === 'string'
		&& value !== ''
		&& !value.startsWith('/')
		&& !value.startsWith('\\')
		&& !/^[A-Za-z]:[\\/]/.test(value)
		&& value.split(/[\\/]/).every((segment) => segment !== '' && segment !== '..');
}

function validateDependencies(repository, dependencies) {
	if (!Array.isArray(dependencies)) {
		throw new Error(`Invalid dependency source list for ${repository}.`);
	}

	const names = new Set();
	for (const dependency of dependencies) {
		if (dependency === null || typeof dependency !== 'object' || !['git', 'phar'].includes(dependency.type)) {
			throw new Error(`Invalid dependency source for ${repository}.`);
		}
		if (typeof dependency.name !== 'string' || !/^[A-Za-z0-9._-]+$/.test(dependency.name) || names.has(dependency.name)) {
			throw new Error(`Invalid or duplicate virion dependency name for ${repository}.`);
		}
		names.add(dependency.name);

		if (dependency.type === 'git') {
			if (typeof dependency.repository !== 'string' || !/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(dependency.repository)
				|| typeof dependency.ref !== 'string' || !/^[a-f0-9]{40}$/i.test(dependency.ref)) {
				throw new Error(`Git virion dependencies for ${repository} require a repository and full commit SHA.`);
			}
			continue;
		}

		if (typeof dependency.url !== 'string' || typeof dependency.filename !== 'string'
			|| !/^[A-Za-z0-9._-]+\.phar$/.test(dependency.filename)
			|| typeof dependency.sha256 !== 'string' || !/^[a-f0-9]{64}$/i.test(dependency.sha256)) {
			throw new Error(`PHAR dependencies for ${repository} require a safe filename and SHA-256.`);
		}
		const url = new URL(dependency.url);
		if (url.protocol !== 'https:' || url.hostname !== 'github.com' || !url.pathname.includes('/releases/download/')) {
			throw new Error(`PHAR dependencies for ${repository} must use a GitHub release URL.`);
		}
	}
}

export function buildMatrix(inventory, compatibility) {
	if (compatibility.schema_version !== 1 || !Array.isArray(compatibility.projects)) {
		throw new Error('Unsupported ecosystem compatibility configuration.');
	}

	const inventoryRepositories = new Set(inventory.repositories.map(({ organization, name }) => `${organization}/${name}`));
	const configuredRepositories = new Set();
	const supportedTypes = new Set(['plugin', 'virion']);
	const matrix = [];

	for (const project of compatibility.projects) {
		const {
			repository,
			project: projectPath,
			roles,
			prepare_dependencies: prepareDependencies,
			virions_path: virionsPath = 'virions',
			submodules = false,
			prepare_script: prepareScript = '',
			dependencies = [],
		} = project;
		if (typeof repository !== 'string' || !inventoryRepositories.has(repository)) {
			throw new Error(`Unexpected repository in compatibility configuration: ${String(repository)}`);
		}
		if (configuredRepositories.has(repository)) {
			throw new Error(`Duplicate compatibility configuration for ${repository}.`);
		}
		configuredRepositories.add(repository);
		if (!isSafeRelativePath(projectPath)) {
			throw new Error(`Invalid project path for ${repository}.`);
		}
		if (!Array.isArray(roles) || roles.length === 0 || roles.some((role) => !supportedTypes.has(role)) || new Set(roles).size !== roles.length) {
			throw new Error(`Invalid build roles for ${repository}.`);
		}
		if (typeof prepareDependencies !== 'boolean' || (prepareDependencies && !roles.includes('plugin'))) {
			throw new Error(`Invalid Composer preparation setting for ${repository}.`);
		}
		if (!isSafeRelativePath(virionsPath)) {
			throw new Error(`Invalid virions path for ${repository}.`);
		}
		if (typeof submodules !== 'boolean') {
			throw new Error(`Invalid submodule setting for ${repository}.`);
		}
		if (typeof prepareScript !== 'string' || (prepareScript !== '' && !isSafeRelativePath(prepareScript))) {
			throw new Error(`Invalid dependency preparation script for ${repository}.`);
		}
		validateDependencies(repository, dependencies);

		for (const type of roles) {
			matrix.push({
				repository,
				project: projectPath,
				type,
				expected_roles: roles.join(','),
				prepare_dependencies: type === 'plugin' && prepareDependencies ? 'true' : 'false',
				virions_path: virionsPath,
				submodules: submodules ? 'true' : 'false',
				prepare_script: prepareScript,
				dependencies,
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

	return matrix;
}

function main() {
	if (process.env.GITHUB_OUTPUT === undefined) {
		throw new Error('GITHUB_OUTPUT is required.');
	}

	const inventory = JSON.parse(readFileSync(resolve(root, 'docs/ecosystem.json'), 'utf8'));
	const compatibility = JSON.parse(readFileSync(resolve(root, 'docs/ecosystem-compatibility.json'), 'utf8'));
	const matrix = buildMatrix(inventory, compatibility);
	appendFileSync(process.env.GITHUB_OUTPUT, `matrix=${JSON.stringify(matrix)}\n`);
	console.log(`Prepared ${matrix.length} role-specific builds across ${compatibility.projects.length} ecosystem repositories.`);
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
	main();
}
