import { appendFileSync, existsSync, readFileSync, realpathSync, statSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { basename, isAbsolute, join } from 'node:path';
import { fileURLToPath } from 'node:url';

function singleLine(value, label) {
  if (typeof value !== 'string' || value.length === 0 || /[\r\n\0]/.test(value)) {
    throw new Error(`${label} must be a nonempty single-line string.`);
  }
  return value;
}

export function readBuild(path) {
  const result = JSON.parse(readFileSync(path, 'utf8'));
  if (result.schema_version !== 1 || result.success !== true || result.command !== 'build') {
    throw new Error('Expected a successful DevTools build JSON schema version 1.');
  }
  const build = result.data;
  const type = build?.type ?? 'plugin';
  if (!['plugin', 'virion'].includes(type)) throw new Error('Unsupported build artifact type.');
  const identity = type === 'virion' ? build?.virion : build?.plugin;
  singleLine(build?.artifact, 'Artifact path');
  singleLine(identity?.name, type === 'virion' ? 'Virion name' : 'Plugin name');
  singleLine(identity?.version, type === 'virion' ? 'Virion version' : 'Plugin version');
  if (!/^[a-f0-9]{64}$/.test(build.sha256)) throw new Error('Invalid build SHA-256.');
  const filename = basename(build.artifact.replaceAll('\\', '/'));
  if (!/^[A-Za-z0-9_.-]+\.phar$/.test(filename)) throw new Error('Unsafe PHAR filename.');
  return { build, filename, type, identity };
}

export function verifyArtifact(path, sha256) {
  if (!existsSync(path) || !statSync(path).isFile() || statSync(path).size === 0) {
    throw new Error(`Missing or empty PHAR: ${path}`);
  }
  if (createHash('sha256').update(readFileSync(path)).digest('hex') !== sha256) {
    throw new Error('PHAR SHA-256 does not match the build result.');
  }
}

export function releaseFlags(version, mode, tag, sha, prerelease) {
  singleLine(version, 'Plugin version');
  if (!['auto', 'true', 'false'].includes(prerelease)) throw new Error('prerelease must be auto, true, or false.');
  if (mode === 'nightly') {
    if (!/^[a-f0-9]{40}$/.test(sha)) throw new Error('Nightly requires a full commit SHA.');
    return { tag: `nightly-${sha}`, prerelease: true };
  }
  if (mode !== 'tag') throw new Error('mode must be tag or nightly.');
  singleLine(tag, 'Release tag');
  if (!/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/.test(version)) {
    throw new Error('Tag releases require a three-component version, optionally with prerelease or build suffixes.');
  }
  if (tag.replace(/^v/, '') !== version) throw new Error(`Tag ${tag} does not exactly match plugin version ${version}.`);
  const detected = version.split('+')[0].includes('-');
  if (detected && prerelease === 'false') throw new Error('A prerelease version cannot be published as stable.');
  return { tag, prerelease: detected || prerelease === 'true' };
}

export function run(mode, metadata, env = process.env) {
  const { build, filename, type, identity } = readBuild(metadata);
  let values;
  if (mode === 'build') {
    if (!isAbsolute(build.artifact)) throw new Error('CLI artifact path must be absolute.');
    const artifact = realpathSync(build.artifact);
    verifyArtifact(artifact, build.sha256);
    values = {
      phar: artifact,
      sha256: build.sha256,
      metadata: realpathSync(metadata),
      'artifact-type': type,
      'artifact-name': identity.name,
      'artifact-version': identity.version,
      'plugin-name': identity.name,
      'plugin-version': identity.version,
    };
  } else if (mode === 'release') {
    if (build.sha256 !== env.EXPECTED_SHA256) throw new Error('Downloaded metadata differs from the build job SHA-256.');
    const artifact = join(env.ARTIFACT_DIRECTORY, filename);
    verifyArtifact(artifact, build.sha256);
    const flags = releaseFlags(identity.version, env.RELEASE_MODE, env.RELEASE_TAG, env.RELEASE_SHA, env.RELEASE_PRERELEASE);
    writeFileSync(join(env.ARTIFACT_DIRECTORY, 'SHA256SUMS.txt'), `${build.sha256}  ${filename}\n`);
    values = {
      phar: realpathSync(artifact),
      tag: flags.tag,
      prerelease: String(flags.prerelease),
      'artifact-type': type,
      'artifact-name': identity.name,
      'artifact-version': identity.version,
      'plugin-name': identity.name,
      'plugin-version': identity.version,
    };
  } else {
    throw new Error('Expected build or release mode.');
  }
  for (const [name, value] of Object.entries(values)) singleLine(value, name);
  appendFileSync(env.GITHUB_OUTPUT, Object.entries(values).map(([name, value]) => `${name}=${value}\n`).join(''));
}

if (process.argv[1] && realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url))) {
  try { run(process.argv[2], process.argv[3]); } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
