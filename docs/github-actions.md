# Build and release Axolotl-PM plugins on GitHub

The composite action calls `bin/devtools.php build --json`, using the same builder as the CLI and server command. It accepts `type: plugin` (default) or `type: virion`, verifies the reported PHAR SHA-256 and exposes the actual path from that result. PHPStan and Composer dependency preparation are opt-in for plugin builds. Virion builds use `phpstan: off` because the action's PHPStan helper is plugin-oriented. A successful build verifies packaging. It does not prove an Axolotl-PM runtime boot.

These interfaces are available in the current `v3.3.0` release. Most repositories should use the maintained `v3` channel: `AxolotlBucketLabs/DevTools@v3` for the composite action and `AxolotlBucketLabs/DevTools/.github/workflows/build-plugin.yml@v3` or `build-virion.yml@v3` for reusable workflows. The `latest` channel is also available for repositories that intentionally want every stable release, including future major versions: use `AxolotlBucketLabs/DevTools@latest` or the corresponding reusable workflow reference. Use `@v3.3.0` when a repository should stay on one release, or a full commit SHA for a strict reproducible pin. The called workflow checks out its builder from the resolved reference using the current job's workflow identity. No release is performed merely by installing DevTools.

## Short build workflow

Create `.github/workflows/build.yml` in a plugin repository.

```yaml
name: Build and validate plugin
on:
  push:
  pull_request:
  workflow_dispatch:
permissions:
  contents: read
jobs:
  build:
    uses: AxolotlBucketLabs/DevTools/.github/workflows/build-plugin.yml@v3
    with:
      php-version: '8.4'
```

The reusable workflow checks out the plugin, sets up Axolotl-PM PHP using setup-helper commit `b8c3f9add4f2ad4a5e2624a1112aba899ee8db0e`, checks out the selected DevTools revision, builds, inspects the PHAR and uploads one artifact containing the PHAR plus `build-metadata.json`. Find that artifact under **Actions -> workflow run -> Artifacts**. The metadata contains the tool version, plugin version, resolved dependency information, checks, and PHAR hash from the versioned [CLI contract](cli.md).

Default `project: .` expects `plugin.yml` and `src/` at the repository root. A plugin without virions needs no Composer manifest or additional DevTools configuration. Local virions remain usable through `virions: virions`. The included `examples/.github/workflows/build.yml` uses the maintained `v3` composite action channel for `HelloShared` and `SharedGreeting`.

For a virion repository, use the following reusable workflow.

```yaml
name: Build and validate virion
on: [push, pull_request]
permissions:
  contents: read
jobs:
  build:
    uses: AxolotlBucketLabs/DevTools/.github/workflows/build-virion.yml@v3
    with:
      project: .
      php-version: '8.4'
```

The virion workflow expects `virion.yml` and `src/`, builds a virion PHAR and uploads it with the same metadata/hash handoff as the plugin workflow. Its outputs include `artifact-type`, `artifact-name`, `artifact-version`, `sha256` and `artifact-id`. A workflow can use a PHAR virion during a plugin build, but it must explicitly check out or download that PHAR into the `virions` directory before invoking DevTools. DevTools does not download runtime dependencies or resolve another branch implicitly.

## Explicit Composer dependency preparation

If the project uses supported Composer Virion v3 packages, commit its `composer.lock` and enable the preparation input.

```yaml
    with:
      prepare-dependencies: true
      virions: .devtools-virions
```

The action explicitly runs `composer install --no-dev --no-scripts --no-plugins --no-autoloader`, then `devtools prepare`, before analysis and build. It fails when a Composer project has no lockfile. It does not run `composer update`, guess library repositories, execute package autoloaders, or include arbitrary `vendor/` files. Composer installation is a network-capable preparation step. Ordinary CLI builds and server startup remain local. See [dependency preparation](dependencies.md) for the exact accepted package format and destination rules. Use a dedicated generated destination such as `.devtools-virions`, separate from manually maintained virions. The maintained [Composer example](../examples/ComposerPlugin) uses a local path repository so the workflow can be tested without Packagist.

For private Composer sources, configure Composer authentication in your own workflow before the action. Do not put tokens into plugin manifests or DevTools configuration. Complex source checkouts and mixed preparation requirements should use the composite action below so the preparation is explicit.

## Step-level action

Use the composite action when your repository needs extra checkouts or checks. Set up a suitable PHP runtime with YAML and PHAR extensions, plus Composer, before invoking it. The reusable workflow's pinned Axolotl-PM setup procedure can be copied for that purpose.

```yaml
      - id: build
        uses: AxolotlBucketLabs/DevTools@v3
        with:
          project: .
          virions: virions
          output: build

      - uses: actions/upload-artifact@v7.0.2
        with:
          name: ${{ steps.build.outputs.plugin-name }}-${{ github.sha }}
          path: ${{ steps.build.outputs.phar }}
          if-no-files-found: error
```

The composite action installs its own locked tool dependencies with scripts and plugins disabled. It does not upload anything on its own. Its relative paths resolve from `GITHUB_WORKSPACE`. It passes absolute paths to the CLI. Direct CLI defaults resolve from the chosen project, as documented in [CLI usage](cli.md).

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `type` | input | `plugin` | `plugin` or `virion`. |
| `project` | input | `.` | Plugin or virion root, according to `type`. |
| `virions` | input | `virions` | Local prepared virion directory for plugin builds. |
| `output` | input | `build` | Output directory. |
| `overwrite` | input | `false` | Explicitly permit replacing output. |
| `prepare-dependencies` | input | `false` | Install locked runtime Composer packages safely, then prepare supported virions. |
| `phpstan` | input | `off` | `off`, `0` through `10`, or `max`. |
| `phpstan-server` | input | empty | Local Axolotl-PM source path, required with PHPStan. |
| `phpstan-paths` | input | empty | Newline-separated local dependency source paths for symbol discovery. |
| `phar` | output | - | Absolute path to the verified PHAR. |
| `plugin-name` | output | - | Name from the build result. |
| `plugin-version` | output | - | Version from the build result. |
| `artifact-type` | output | - | `plugin` or `virion`. |
| `artifact-name` | output | - | Plugin or virion name. |
| `artifact-version` | output | - | Plugin or virion version. |
| `sha256` | output | - | SHA-256 of the exact PHAR. |
| `metadata` | output | - | Absolute path to the CLI JSON build result. |

The reusable build workflow adds `php-version`, `pm-version-major`, `artifact-name`, `axolotl-ref`, and `phpstan-server` inputs. Set `axolotl-ref` to an Axolotl-PM Git ref before PHPStan. Use the verified release tag `5.51.1` for ordinary analysis, or its full commit SHA `e881fc1bc1c401545baf6bd1f824e41ff3f57bd1` when strict reproducibility is required. Keep the server source ref independent of the tested runtime version. The workflow exports `plugin-name`, `plugin-version`, `sha256`, `artifact-name`, and immutable `artifact-id`. Local artifact paths are only meaningful inside the build job. Another job must download using `artifact-id`.

## Optional static analysis

Check out the Axolotl-PM source, then pass its local path to the composite action.

```yaml
      - uses: actions/checkout@v7.0.1
        with:
          repository: axolotl-pm/PocketMine-MP
          ref: 5.51.1
          path: .devtools-server
          persist-credentials: false
      - id: build
        uses: AxolotlBucketLabs/DevTools@v3
        with:
          phpstan: '4'
          phpstan-server: .devtools-server
```

The analysis helper installs that server's production dependencies with scripts and plugins disabled. It runs the bundled PHPStan PHAR from a temporary path outside DevTools' Composer project, so DevTools' own development dependencies cannot satisfy missing server symbols. It analyzes plugin `src/` and discovers symbols from Axolotl-PM, declared virions, and optional `phpstan-paths`. Level 4 is an example, not the default. Missing sources or analysis errors fail the action. This is static analysis, not a running server test. Newline-separated server paths remain accepted for existing callers, but only Axolotl-PM is officially supported.

For the reusable workflow, set `phpstan: max` and `axolotl-ref` to the Axolotl-PM release tag or source ref used for analysis. Use a full SHA when the repository needs an immutable source snapshot. Use `phpstan-server` only for additional sources already present in the caller workspace.

## Tag release

Create `.github/workflows/release.yml` in the plugin repository.

```yaml
name: Publish plugin release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
  actions: read
jobs:
  release:
    uses: AxolotlBucketLabs/DevTools/.github/workflows/release-plugin.yml@v3
    with:
      mode: tag
```

The reusable release workflow builds with read-only repository permissions. Its publish job checks out the release tools from its own workflow commit, downloads the immutable artifact ID, checks the PHAR bytes against both downloaded metadata and the SHA-256 passed directly from the build job, then publishes **those bytes without rebuilding**. The release assets are the PHAR, `build-metadata.json`, and `SHA256SUMS.txt`. The publish job executes verification tools, not plugin code. Only publication has `contents: write`.

A tag must match the complete `plugin.yml` version, with an optional leading `v`. For example, `v1.2.3-rc.1` requires `version: 1.2.3-rc.1`, not `1.2.3`. Versions have three numeric components and optional prerelease and build suffixes. Prerelease suffixes automatically set GitHub prerelease status. Input `prerelease: 'true'` may mark an otherwise stable version as a prerelease. `'false'` cannot turn an explicit prerelease version into a stable release. The default is `'auto'`.

An existing release is not replaced. A rerun that reaches an already published release fails rather than overwriting its assets. The workflow exports `artifact-id`, `sha256`, and `release-url`. Tag publication is allowed only for tag push events. Pull requests cannot publish through this workflow.

## Optional nightly prereleases

Create a separate workflow only if nightly publication is wanted.

```yaml
name: Publish nightly plugin prerelease
on:
  schedule:
    - cron: '0 18 * * *'
  workflow_dispatch:
permissions:
  contents: write
  actions: read
jobs:
  nightly:
    uses: AxolotlBucketLabs/DevTools/.github/workflows/release-plugin.yml@v3
    with:
      mode: nightly
```

Nightly runs use `nightly-<full commit SHA>` tags, always prerelease and never the shared `latest` channel. They create an immutable release per commit rather than force-moving a shared `nightly` tag. A repeated run of the same commit fails at publication if that nightly release already exists. There is no automatic deletion or retention policy for releases. GitHub artifact retention does not delete release assets. Scheduled and manual branch events are accepted. Pull-request events are excluded.

## Select the builder revision

Use the `v3` major channel for the normal developer workflow. DevTools moves that tag only to a compatible stable v3 release after its release checks pass. This avoids requiring every consumer repository to edit its workflow for each compatible patch or minor release.

Use the `latest` channel only when the repository intentionally accepts stable releases across major versions.

```yaml
uses: AxolotlBucketLabs/DevTools/.github/workflows/build-plugin.yml@latest
```

DevTools moves `latest` only to a validated stable release. It may move from v3 to v4, so a repository using it must be prepared for breaking changes. This is an opt-in convenience channel, not the default recommendation.

Use an exact release when a repository needs controlled upgrades.

```yaml
uses: AxolotlBucketLabs/DevTools/.github/workflows/build-plugin.yml@v3.3.0
```

Use a full commit SHA for strict reproducibility or a supply-chain policy. Resolve the commit behind the reviewed release tag and keep the version in a comment.

```sh
git ls-remote https://github.com/AxolotlBucketLabs/DevTools.git 'refs/tags/v3.3.0^{}'
```

```yaml
uses: AxolotlBucketLabs/DevTools/.github/workflows/build-plugin.yml@<full-commit-sha> # v3.3.0
```

The called job reads its own `workflow_sha` and `workflow_repository`, checks out that revision, and verifies the checked-out HEAD and CLI contract before building. The same rule applies to release verification. The v3 workflow contract no longer accepts the removed `devtools-ref` input. Remove it when migrating a v1 caller. GitHub's workflow identity context is available on GitHub.com. These reusable workflows are not supported on GitHub Enterprise Server without that context.

## Migrate from v1

Update the reusable workflow reference to `@v3` and remove legacy inputs that v3 no longer declares. Use `@latest` only if the repository intentionally accepts major upgrades. Use `@v3.3.0` or a full SHA when the repository requires a controlled or strict pin.

```diff
 with:
-  devtools-ref: <old-builder-sha>
-  retention-days: 14
   php-version: '8.4'
```

The v3 workflow uses the repository's own artifact and log retention policy. It also leaves each push and pull request build eligible to finish instead of cancelling an earlier build on the same ref.

## Releasing DevTools itself

The project's own release process is separate from the consumer plugin workflow. `release-candidate.yml` performs all build and check work from an explicitly selected commit and uploads a reviewable candidate **before** a release tag or publication is needed. `release.yml` only promotes an explicitly selected successful candidate run and artifact ID for an already existing tag. It verifies source, run, attempt and workflow identity through GitHub's API, checks the downloaded archive against GitHub's SHA-256 digest, and checks every PHAR, examples ZIP and release-notes hash before publishing. It never rebuilds, regenerates notes, or repackages examples. See the exact [maintainer release procedure](development.md#release-process).

## Validation boundaries

Local behavior tests exercise build-result parsing, hash handoff, tampered bytes or metadata, tag mismatches, prerelease rules, and nightly tag generation. CI runs these tests and the actual composite action against the documented plugin example. Workflow linting does not prove hosted upload, download or publication behavior. Runtime artifact validation belongs in a separate Axolotl-PM test before publication when your project requires it. The release workflow guarantees packaging and byte continuity rather than gameplay correctness.
