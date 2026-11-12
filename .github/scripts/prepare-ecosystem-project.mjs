import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import {
	copyFileSync,
	existsSync,
	lstatSync,
	mkdirSync,
	mkdtempSync,

	readFileSync,
	readdirSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { basename, dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { tmpdir } from 'node:os';
import { pathToFileURL } from 'node:url';

const PHAR_MAX_BYTES = 32 * 1024 * 1024;
const GIT_ARCHIVE_MAX_FILE_BYTES = 64 * 1024 * 1024;
const RELEASE_ASSET_HOSTS = new Set([
	'github.com',
	'github-releases.githubusercontent.com',
	'objects.githubusercontent.com',
	'release-assets.githubusercontent.com',
]);

function run(command, args, options = {}) {
	const result = spawnSync(command, args, {
		cwd: options.cwd,
		encoding: options.encoding ?? 'utf8',
		input: options.input,
		maxBuffer: options.maxBuffer,
		stdio: options.stdio ?? 'inherit',
	});
	if (result.error !== undefined) {
		throw result.error;
	}
	if (result.status !== 0) {
		throw new Error(`${command} exited with status ${String(result.status)}.`);
	}
	return result.stdout;
}

function requireRelativePath(value, label) {
	if (typeof value !== 'string' || value === '' || isAbsolute(value)
		|| /^[A-Za-z]:[\\/]/.test(value)
		|| !value.split(/[\\/]/).every((segment) => segment !== '' && segment !== '..')) {
		throw new Error(`${label} must be a safe relative path.`);
	}
	return value;
}

function parseDependencies(value) {
	const dependencies = JSON.parse(value);
	if (!Array.isArray(dependencies)) {
		throw new Error('Dependency source configuration must be a JSON array.');
	}
	const names = new Set();
	for (const dependency of dependencies) {
		if (dependency === null || typeof dependency !== 'object' || !['git', 'phar'].includes(dependency.type)) {
			throw new Error('Dependency source has an unsupported type.');
		}
		if (typeof dependency.name !== 'string' || !/^[A-Za-z0-9._-]+$/.test(dependency.name) || names.has(dependency.name)) {
			throw new Error('Dependency source has an invalid or duplicate name.');
		}
		names.add(dependency.name);
		if (dependency.type === 'git') {
			if (typeof dependency.repository !== 'string' || !/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(dependency.repository)
				|| typeof dependency.ref !== 'string' || !/^[a-f0-9]{40}$/i.test(dependency.ref)) {
				throw new Error(`Git dependency ${dependency.name} must use a repository and full commit SHA.`);
			}
			continue;
		}
		if (typeof dependency.url !== 'string' || typeof dependency.filename !== 'string'
			|| !/^[A-Za-z0-9._-]+\.phar$/.test(dependency.filename)
			|| typeof dependency.sha256 !== 'string' || !/^[a-f0-9]{64}$/i.test(dependency.sha256)) {
			throw new Error(`PHAR dependency ${dependency.name} must use a safe filename and SHA-256.`);
		}
		const url = new URL(dependency.url);
		if (url.protocol !== 'https:' || url.hostname !== 'github.com' || !url.pathname.includes('/releases/download/')) {
			throw new Error(`PHAR dependency ${dependency.name} must use a GitHub release URL.`);
		}
	}
	return dependencies;
}

function ensureInside(root, candidate, label) {
	const relativePath = relative(resolve(root), resolve(candidate));
	if (relativePath === '' || relativePath === '..' || relativePath.startsWith(`..${sep}`) || isAbsolute(relativePath)) {
		throw new Error(`${label} resolves outside its allowed directory.`);
	}
	return resolve(candidate);
}

function copyTree(source, destination) {
	if (!existsSync(source)) {
		return;
	}
	const sourceStat = lstatSync(source);
	if (sourceStat.isSymbolicLink() || !sourceStat.isDirectory()) {
		throw new Error(`Virion source is not a regular directory: ${source}`);
	}
	mkdirSync(destination, { recursive: true });
	for (const entry of readdirSync(source)) {
		if (entry === '.git') {
			continue;
		}
		const from = join(source, entry);
		const to = join(destination, entry);
		if (existsSync(to)) {
			throw new Error(`Two virion sources provide the same path: ${to}`);
		}
		const stat = lstatSync(from);
		if (stat.isSymbolicLink()) {
			throw new Error(`Virion source contains an unsupported symbolic link: ${from}`);
		} else if (stat.isDirectory()) {
			copyTree(from, to);
		} else if (stat.isFile()) {
			mkdirSync(dirname(to), { recursive: true });
			copyFileSync(from, to);
		} else {
			throw new Error(`Unsupported virion source entry: ${from}`);
		}
	}
}

function copyGitVirion(dependency, destination, temporaryRoot) {
	const repositoryPath = join(temporaryRoot, `git-${dependency.name}`);
	mkdirSync(repositoryPath);
	run('git', ['init', repositoryPath]);
	run('git', ['-C', repositoryPath, 'remote', 'add', 'origin', `https://github.com/${dependency.repository}.git`]);
	run('git', ['-C', repositoryPath, 'fetch', '--depth=1', 'origin', dependency.ref]);
	const revision = execFileSync('git', ['-C', repositoryPath, 'rev-parse', 'FETCH_HEAD'], { encoding: 'utf8' }).trim();
	if (revision.toLowerCase() !== dependency.ref.toLowerCase()) {
		throw new Error(`Git dependency ${dependency.name} resolved to an unexpected commit.`);
	}
	run('git', ['-C', repositoryPath, 'checkout', '--detach', revision]);
	const trackedFiles = execFileSync('git', ['-C', repositoryPath, 'ls-files', '-z'], { encoding: 'utf8' }).split('\0').filter(Boolean);
	for (const file of trackedFiles) {
		const source = ensureInside(repositoryPath, join(repositoryPath, file), `Git dependency ${dependency.name}`);
		const sourceStat = lstatSync(source);
		if (sourceStat.isSymbolicLink() || !sourceStat.isFile()) {
			throw new Error(`Git dependency ${dependency.name} contains an unsupported file: ${file}`);
		}
		if (sourceStat.size > GIT_ARCHIVE_MAX_FILE_BYTES) {
			throw new Error(`Git dependency ${dependency.name} contains an oversized file: ${file}`);
		}
		const target = ensureInside(destination, join(destination, file), `Git dependency ${dependency.name}`);
		mkdirSync(dirname(target), { recursive: true });
		copyFileSync(source, target);
	}
	if (!existsSync(join(destination, 'virion.yml'))) {
		throw new Error(`Git dependency ${dependency.name} has no root virion.yml.`);
	}
	return revision;
}

async function downloadVirionPhar(dependency, destination) {
	const response = await fetch(dependency.url, { redirect: 'follow' });
	if (!response.ok || !RELEASE_ASSET_HOSTS.has(new URL(response.url).hostname)) {
		throw new Error(`Could not download the pinned PHAR dependency ${dependency.name}.`);
	}
	if (response.body === null) {
		throw new Error(`PHAR dependency ${dependency.name} has no response body.`);
	}
	const chunks = [];
	let totalBytes = 0;
	const reader = response.body.getReader();
	while (true) {
		const { done, value } = await reader.read();
		if (done) {
			break;
		}
		totalBytes += value.byteLength;
		if (totalBytes > PHAR_MAX_BYTES) {
			await reader.cancel();
			throw new Error(`PHAR dependency ${dependency.name} exceeds the supported size.`);
		}
		chunks.push(Buffer.from(value));
	}
	const bytes = Buffer.concat(chunks, totalBytes);
	const sha256 = createHash('sha256').update(bytes).digest('hex');
	if (sha256.toLowerCase() !== dependency.sha256.toLowerCase()) {
		throw new Error(`PHAR dependency ${dependency.name} does not match its configured SHA-256.`);
	}
	const target = join(destination, dependency.filename);
	writeFileSync(target, bytes, { flag: 'wx' });
	return sha256;
}

async function prepare() {
	const projectDirectory = resolve(process.env.PROJECT_DIR ?? '');
	const devtoolsDirectory = resolve(process.env.DEVTOOLS_DIR ?? '');
	const virionsPath = requireRelativePath(process.env.VIRIONS_PATH ?? '', 'Virions path');
	const prepareDependencies = process.env.PREPARE_DEPENDENCIES;
	if (prepareDependencies !== 'true' && prepareDependencies !== 'false') {
		throw new Error('Composer preparation must be true or false.');
	}
	if (!existsSync(projectDirectory) || !existsSync(devtoolsDirectory)) {
		throw new Error('Project or DevTools source directory is missing.');
	}
	const dependencies = parseDependencies(process.env.DEPENDENCIES_JSON ?? '[]');
	const configuredVirions = ensureInside(projectDirectory, join(projectDirectory, virionsPath), 'Project virions path');
	const prepareScript = process.env.PREPARE_SCRIPT ?? '';
	if (prepareScript !== '') {
		requireRelativePath(prepareScript, 'Preparation script');
	}

	const tempBase = resolve(process.env.RUNNER_TEMP ?? tmpdir());
	const temporaryRoot = mkdtempSync(join(tempBase, 'devtools-ecosystem-'));
	const composerVirions = join(temporaryRoot, 'composer-virions');
	const dependencyVirions = join(temporaryRoot, 'source-virions');
	const assembledVirions = join(temporaryRoot, 'virions');
	const summary = [];

	try {
		if (prepareScript !== '') {
			const scriptPath = ensureInside(projectDirectory, join(projectDirectory, prepareScript), 'Preparation script');
			if (!existsSync(scriptPath)) {
				throw new Error(`Project preparation script does not exist: ${prepareScript}`);
			}
			run('php', [scriptPath], { cwd: projectDirectory });
			summary.push(`- Project preparation: \`${prepareScript}\``);
		}

		if (prepareDependencies === 'true') {
			run('composer', [
				'install', `--working-dir=${devtoolsDirectory}`, '--prefer-dist', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins', '--ignore-platform-req=ext-webrtc',
			]);
			run('composer', [
				'install', `--working-dir=${projectDirectory}`, '--no-dev', '--no-scripts', '--no-plugins', '--no-autoloader', '--prefer-dist', '--no-interaction', '--no-progress', '--ignore-platform-req=ext-webrtc',
			]);
			run('php', [
				join(devtoolsDirectory, 'bin', 'devtools.php'), 'prepare', `--project=${projectDirectory}`, `--virions=${composerVirions}`, '--json',
			]);
			summary.push('- Composer preparation: completed from the committed lockfile');
		}

		mkdirSync(dependencyVirions, { recursive: true });
		for (const dependency of dependencies) {
			if (dependency.type === 'git') {
				const destination = join(dependencyVirions, dependency.name);
				mkdirSync(destination);
				const revision = copyGitVirion(dependency, destination, temporaryRoot);
				summary.push(`- Virion source: \`${dependency.repository}\` at \`${revision}\``);
				continue;
			}
			mkdirSync(dependencyVirions, { recursive: true });
			const sha256 = await downloadVirionPhar(dependency, dependencyVirions);
			summary.push(`- Virion PHAR: \`${dependency.name}\` SHA-256 \`${sha256}\``);
		}

		mkdirSync(assembledVirions);
		copyTree(configuredVirions, assembledVirions);
		copyTree(composerVirions, assembledVirions);
		copyTree(dependencyVirions, assembledVirions);

		if (!existsSync(process.env.GITHUB_OUTPUT ?? '')) {
			throw new Error('GITHUB_OUTPUT is required.');
		}
		writeFileSync(process.env.GITHUB_OUTPUT, `virions=${assembledVirions}\n`, { flag: 'a' });
		if (process.env.GITHUB_STEP_SUMMARY !== undefined) {
			writeFileSync(process.env.GITHUB_STEP_SUMMARY, `${summary.join('\n')}\n`, { flag: 'a' });
		}
	} catch (error) {
		rmSync(temporaryRoot, { recursive: true, force: true });
		throw error;
	}
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
	prepare().catch((error) => {
		console.error(error instanceof Error ? error.message : String(error));
		process.exitCode = 1;
	});
}

export { parseDependencies, requireRelativePath };
