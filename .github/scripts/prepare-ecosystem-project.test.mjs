import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseDependencies, requireRelativePath } from './prepare-ecosystem-project.mjs';

test('dependency staging accepts only pinned Git commits and checksummed PHAR releases', () => {
	const dependencies = parseDependencies(JSON.stringify([
		{ type: 'git', name: 'Lib', repository: 'owner/repo', ref: 'a'.repeat(40) },
		{ type: 'phar', name: 'OtherLib', url: 'https://github.com/owner/repo/releases/download/v1/OtherLib.phar', filename: 'OtherLib.phar', sha256: 'b'.repeat(64) },
	]));
	assert.equal(dependencies.length, 2);
	assert.throws(() => parseDependencies(JSON.stringify([{ type: 'git', name: 'Lib', repository: 'owner/repo', ref: 'main' }])), /full commit SHA/);
	assert.throws(() => parseDependencies(JSON.stringify([{ type: 'phar', name: 'Lib', url: 'https://example.com/Lib.phar', filename: 'Lib.phar', sha256: 'a'.repeat(64) }])), /GitHub release URL/);
});

test('dependency staging rejects paths that can escape the checkout', () => {
	assert.equal(requireRelativePath('.devtools-virions', 'Virions path'), '.devtools-virions');
	assert.throws(() => requireRelativePath('../virions', 'Virions path'), /safe relative path/);
	assert.throws(() => requireRelativePath('/tmp/virions', 'Virions path'), /safe relative path/);
	assert.throws(() => requireRelativePath('C:\\outside\\virions', 'Virions path'), /safe relative path/);
});
