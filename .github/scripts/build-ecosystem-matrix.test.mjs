import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { buildMatrix } from './build-ecosystem-matrix.mjs';

const root = new URL('../../', import.meta.url);
const inventory = JSON.parse(readFileSync(new URL('docs/ecosystem.json', root), 'utf8'));
const compatibility = JSON.parse(readFileSync(new URL('docs/ecosystem-compatibility.json', root), 'utf8'));

test('ecosystem compatibility profiles produce one build for every declared role', () => {
	const matrix = buildMatrix(inventory, compatibility);
	const expectedRoleCount = compatibility.projects.reduce((count, project) => count + project.roles.length, 0);

	assert.equal(matrix.length, expectedRoleCount);
	assert.equal(matrix.length, 148);
	assert.ok(matrix.some(({ repository, type, submodules }) => repository === 'NhanAZ-Plugins/AdvancedRules' && type === 'plugin' && submodules === 'true'));
	assert.ok(matrix.some(({ repository, type, dependencies }) => repository === 'thebigcrafter/TrashCan' && type === 'plugin' && dependencies[0]?.type === 'phar'));
});

test('ecosystem compatibility profiles reject unpinned or unsafe dependency inputs', () => {
	const invalidProfiles = [
		{ dependencies: [{ type: 'git', name: 'Lib', repository: 'owner/repo', ref: 'main' }] },
		{ virions_path: '../virions' },
		{ virions_path: 'C:\\outside\\virions' },
		{ dependencies: [{ type: 'phar', name: 'Lib', url: 'http://github.com/owner/repo/releases/download/v1/Lib.phar', filename: 'Lib.phar', sha256: 'a'.repeat(64) }] },
	];

	for (const invalidProfile of invalidProfiles) {
		const invalidCompatibility = structuredClone(compatibility);
		invalidCompatibility.projects[0] = { ...invalidCompatibility.projects[0], ...invalidProfile };
		assert.throws(() => buildMatrix(inventory, invalidCompatibility));
	}
});
