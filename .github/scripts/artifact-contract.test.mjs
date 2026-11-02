import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { readBuild, releaseFlags, run } from './artifact-contract.mjs';

test('tag releases compare full version and never publish a prerelease as stable', () => {
  assert.deepEqual(releaseFlags('1.2.3', 'tag', 'v1.2.3', '', 'auto'), { tag: 'v1.2.3', prerelease: false });
  assert.equal(releaseFlags('1.2.3-rc.1', 'tag', 'v1.2.3-rc.1', '', 'auto').prerelease, true);
  assert.equal(releaseFlags('1.2.3', 'tag', '1.2.3', '', 'true').prerelease, true);
  assert.throws(() => releaseFlags('1.2.3', 'tag', 'v1.2.3-rc.1', '', 'auto'), /exactly match/);
  assert.throws(() => releaseFlags('1.2.3-rc.1', 'tag', 'v1.2.3-rc.1', '', 'false'), /cannot be published/);
  assert.throws(() => releaseFlags('1.2', 'tag', 'v1.2', '', 'auto'), /three-component/);
  assert.throws(() => releaseFlags('1.2.3', 'tag', 'v1.2.3', '', 'invalid'), /prerelease must/);
  assert.throws(() => releaseFlags('1.2.3\n', 'tag', 'v1.2.3\n', '', 'auto'), /single-line/);
});

test('nightly uses immutable full commit tags', () => {
  const sha = 'a'.repeat(40);
  assert.deepEqual(releaseFlags('dev', 'nightly', '', sha, 'auto'), { tag: `nightly-${sha}`, prerelease: true });
  assert.throws(() => releaseFlags('dev', 'nightly', '', 'short', 'auto'), /full commit/);
});

test('build and publication consume the same bytes; tampering and metadata injection fail', () => {
  const dir = mkdtempSync(join(tmpdir(), 'devtools-action-'));
  try {
    const phar = join(dir, 'Example.phar');
    const metadata = join(dir, 'build-metadata.json');
    const output = join(dir, 'output');
    const bytes = Buffer.from('representative immutable artifact bytes');
    writeFileSync(phar, bytes);
    const sha256 = createHash('sha256').update(bytes).digest('hex');
    const result = { schema_version: 1, success: true, command: 'build', data: { artifact: phar, sha256, plugin: { name: 'Example', version: '1.2.3' } } };
    writeFileSync(metadata, JSON.stringify(result));
    const env = { GITHUB_OUTPUT: output, EXPECTED_SHA256: sha256, ARTIFACT_DIRECTORY: dir, RELEASE_MODE: 'tag', RELEASE_TAG: 'v1.2.3', RELEASE_SHA: 'a'.repeat(40), RELEASE_PRERELEASE: 'auto' };
    run('build', metadata, env);
    run('release', metadata, env);
    assert.match(readFileSync(output, 'utf8'), /tag=v1.2.3\nprerelease=false/);
    assert.equal(readFileSync(join(dir, 'SHA256SUMS.txt'), 'utf8'), `${sha256}  Example.phar\n`);
    assert.throws(() => run('release', metadata, { ...env, EXPECTED_SHA256: 'b'.repeat(64) }), /differs from the build job/);
    writeFileSync(phar, 'changed after build');
    assert.throws(() => run('release', metadata, env), /SHA-256 does not match/);
    result.data.plugin.name = 'Example\nphar=injected';
    writeFileSync(metadata, JSON.stringify(result));
    assert.throws(() => readBuild(metadata), /single-line/);
    result.data.plugin.name = 'Example';
    result.success = false;
    writeFileSync(metadata, JSON.stringify(result));
    assert.throws(() => readBuild(metadata), /successful DevTools build/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('virion build metadata uses the virion identity and the same artifact checks', () => {
  const dir = mkdtempSync(join(tmpdir(), 'devtools-virion-action-'));
  try {
    const phar = join(dir, 'SharedGreeting.phar');
    const metadata = join(dir, 'build-metadata.json');
    const output = join(dir, 'output');
    const bytes = Buffer.from('representative virion artifact bytes');
    writeFileSync(phar, bytes);
    const sha256 = createHash('sha256').update(bytes).digest('hex');
    writeFileSync(metadata, JSON.stringify({
      schema_version: 1,
      success: true,
      command: 'build',
      data: {
        type: 'virion',
        artifact: phar,
        sha256,
        virion: { name: 'SharedGreeting', version: '1.0.0', antigen: 'Example\\SharedGreeting' },
      },
    }));
    run('build', metadata, { GITHUB_OUTPUT: output });
    const values = readFileSync(output, 'utf8');
    assert.match(values, /artifact-type=virion\n/);
    assert.match(values, /artifact-name=SharedGreeting\n/);
    assert.match(values, /artifact-version=1.0.0\n/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
