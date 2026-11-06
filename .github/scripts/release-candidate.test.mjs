import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createCandidate, resolveCandidate, validateIdentity, verifyArchive, verifyCandidate } from './release-candidate.mjs';

const commit = 'a'.repeat(40);
const workflow = '.github/workflows/release-candidate.yml';
const repository = 'AxolotlBucketLabs/DevTools';
const env = { GITHUB_EVENT_NAME: 'workflow_dispatch', GITHUB_REF: 'refs/heads/release-preparation', GITHUB_SHA: commit, GITHUB_RUN_ID: '123', GITHUB_RUN_ATTEMPT: '2', GITHUB_REPOSITORY: repository, GITHUB_WORKFLOW_REF: `${repository}/${workflow}@refs/heads/release-preparation` };

function apiFixture() {
  const run = { id: 123, run_attempt: 2, repository: { full_name: repository }, head_repository: { full_name: repository }, path: workflow, workflow_id: 456, event: 'workflow_dispatch', head_branch: 'release-preparation', head_sha: commit, status: 'completed', conclusion: 'success' };
  const artifact = { id: 789, name: `DevTools-candidate-${commit}-123-2`, expired: false, digest: `sha256:${'b'.repeat(64)}`, workflow_run: { id: 123, head_sha: commit } };
  const expected = { repository, commit, run_id: '123', artifact_id: '789', workflow_id: 456 };
  return { run, artifact, expected };
}

test('candidate identity rejects wrong source, workflow, run, attempt, expiry and unsuccessful gates', () => {
  const { run, artifact, expected } = apiFixture();
  const identity = validateIdentity(run, artifact, expected);
  assert.equal(identity.commit, commit);
  assert.equal(identity.run_attempt, '2');
  for (const patch of [
    { id: 999 }, { head_sha: 'c'.repeat(40) }, { path: '.github/workflows/ci.yml' },
    { workflow_id: 999 }, { event: 'pull_request' }, { conclusion: 'failure' },
    { status: 'in_progress' }, { repository: { full_name: 'another/repo' } },
    { head_repository: { full_name: 'fork/DevTools' } }, { run_attempt: 3 },
  ]) assert.throws(() => validateIdentity({ ...run, ...patch }, artifact, expected));
  for (const patch of [
    { id: 999 }, { expired: true }, { digest: null }, { name: 'unreviewed-artifact' },
    { workflow_run: { id: 999, head_sha: commit } },
    { workflow_run: { id: 123, head_sha: 'c'.repeat(40) } },
  ]) assert.throws(() => validateIdentity(run, { ...artifact, ...patch }, expected));
});

test('resolver peels annotated tags and never selects a different run or artifact', () => {
  const { run, artifact } = apiFixture();
  const calls = [];
  const api = endpoint => {
    calls.push(endpoint);
    const responses = {
      [`repos/${repository}/git/ref/tags/v1.0.0`]: { object: { type: 'tag', sha: 'd'.repeat(40) } },
      [`repos/${repository}/git/tags/${'d'.repeat(40)}`]: { object: { type: 'commit', sha: commit } },
      [`repos/${repository}/actions/runs/123`]: run,
      [`repos/${repository}/actions/artifacts/789`]: artifact,
      [`repos/${repository}/actions/workflows/release-candidate.yml`]: { id: 456 },
    };
    assert.ok(endpoint in responses, `Unexpected endpoint: ${endpoint}`);
    return responses[endpoint];
  };
  const inputs = { GH_REPO: repository, CANDIDATE_RUN_ID: '123', CANDIDATE_ARTIFACT_ID: '789', RELEASE_TAG: 'v1.0.0' };
  assert.equal(resolveCandidate(inputs, api).artifact_id, '789');
  assert.equal(calls.length, 5);
  assert.throws(() => resolveCandidate({ ...inputs, CANDIDATE_RUN_ID: '../123' }, api), /positive integers/);
  assert.throws(() => resolveCandidate({ ...inputs, RELEASE_TAG: 'main' }, api), /three-component/);
});

test('verified candidate preserves PHAR, example archive and notes and rejects relabeling or tampering', () => {
  const dir = mkdtempSync(join(tmpdir(), 'devtools-candidate-'));
  try {
    for (const name of ['DevTools-1.0.0.phar', 'DevTools-examples-1.0.0.zip', 'RELEASE_NOTES.md']) {
      writeFileSync(join(dir, name), `original bytes of ${name}`);
    }
    const manifest = createCandidate(dir, '1.0.0', env);
    const { run, artifact, expected } = apiFixture();
    const identity = validateIdentity(run, artifact, expected);
    assert.deepEqual(verifyCandidate(dir, identity, 'v1.0.0'), { version: '1.0.0', prerelease: 'false' });
    assert.throws(() => verifyCandidate(dir, identity, 'v2.0.0'), /exactly match/);
    assert.throws(() => verifyCandidate(dir, { ...identity, commit: 'c'.repeat(40) }, 'v1.0.0'), /provenance mismatch/);
    for (const name of Object.keys(manifest.assets)) {
      const bytes = readFileSync(join(dir, name));
      writeFileSync(join(dir, name), 'changed');
      assert.throws(() => verifyCandidate(dir, identity, 'v1.0.0'), /SHA-256/);
      writeFileSync(join(dir, name), bytes);
    }
    writeFileSync(join(dir, 'unexpected.txt'), 'unreviewed');
    assert.throws(() => verifyCandidate(dir, identity, 'v1.0.0'), /unexpected files/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('downloaded archive must match the digest returned by GitHub before extraction', () => {
  const dir = mkdtempSync(join(tmpdir(), 'devtools-archive-'));
  try {
    const path = join(dir, 'candidate.zip');
    const bytes = Buffer.from('representative uploaded archive bytes');
    const archive_digest = `sha256:${createHash('sha256').update(bytes).digest('hex')}`;
    writeFileSync(path, bytes);
    verifyArchive(path, { archive_digest });
    writeFileSync(path, 'different archive and possibly different metadata');
    assert.throws(() => verifyArchive(path, { archive_digest }), /archive SHA-256 differs/);
    assert.throws(() => verifyArchive(path, { archive_digest: '' }), /Missing/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
