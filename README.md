# DevTools

Load [Axolotl-PM](https://github.com/axolotl-pm/PocketMine-MP) plugins from folders, share development virions, and build standalone PHARs through server commands, the CLI, or GitHub Actions.

See the [architecture overview](ARCHITECTURE.md) for how folder loading, shared virions, and PHAR builds fit together.

[![CI](https://github.com/AxolotlBucketLabs/DevTools/actions/workflows/ci.yml/badge.svg)](https://github.com/AxolotlBucketLabs/DevTools/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/AxolotlBucketLabs/DevTools)](https://github.com/AxolotlBucketLabs/DevTools/releases/latest)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Discord server online members](https://img.shields.io/discord/1568295845374206052?label=Discord&amp;logo=discord&amp;logoColor=white&amp;color=5865F2)](https://discord.gg/Pk4FrNvHnM)

## Quick start

1. Download the DevTools PHAR from the [latest release](https://github.com/AxolotlBucketLabs/DevTools/releases/latest). GitHub's "Source code" archives are for developing DevTools.
2. Stop your Axolotl-PM server and copy the PHAR to `plugins/DevTools.phar`.
3. Put your development plugin folder, containing `plugin.yml` and `src/`, directly inside `plugins/`.
4. Start the server. Confirm that DevTools reports folder loading, shared virions, and PHAR building as ready, then run `/devtools status` as an operator.
5. Run `/devtools doctor YourPlugin` and `/devtools build YourPlugin`. Test `build/YourPlugin.phar` on a clean server before deployment.

Install DevTools itself as a PHAR so its folder loader can start. Restart the server after adding or editing source plugins.

## Build a plugin on GitHub

Copy the [short build workflow](docs/github-actions.md#short-build-workflow) to `.github/workflows/build.yml` and push. It follows the maintained DevTools `v3` workflow channel by default. Use `@latest` when you intentionally want every stable release, including future major versions. Use a release tag or full commit SHA when your project requires a controlled or strict pin. Download the PHAR from the run's **Artifacts** section. A plugin without dependencies needs only `plugin.yml` and `src/`. No server checkout, project-local Composer installation, `devtools.yml`, or `virions/` is required.

## What it does

- Loads folder plugins through the server's plugin manager, which handles API compatibility, dependencies, duplicates, load order, and enable failures.
- Loads one compatible copy of each local development virion from a folder or PHAR in `virions/` on the shared thread-safe class loader. A folder is preferred over a same-name PHAR and the PHAR is reported as skipped.
- Selects the highest semantic virion version satisfying every declared constraint and reports dependency conflicts.
- Builds a SHA-256 PHAR with declared virions rewritten into private namespaces using a PHP syntax tree.
- Builds standalone virion PHARs and dual-role projects as separate plugin and virion artifacts.
- Extracts plugin or virion PHARs to a folder or ZIP through the server command and CLI.
- Offers optional PHPStan analysis in GitHub Actions against an explicitly selected server source.
- Validates PHAR entries before extraction and stages replacement with a backup of existing output.
- Localizes lifecycle messages, command summaries and common build results to Axolotl-PM's supported languages.

Server startup and ordinary builds use local dependencies. DevTools does not replace Composer or provide hot reload.

For new projects, declare libraries in `composer.json` and commit `composer.lock`. The [Composer workflow](docs/dependencies.md#composer-preparation) prepares locked dependencies for standalone PHAR builds. Existing local virion projects remain supported. During server development, matching source folders in `virions/` can satisfy locked Composer dependencies without preparation. See [local Composer sources](docs/shared-virions.md#local-composer-sources) for the checks and configuration.

For troubleshooting, set `logging.debug: true` in the server `config.yml`, or add `--debug` to a CLI command. DEBUG is off by default and reports lifecycle, discovery, dependency-selection and artifact summaries without printing source contents or credentials.

## Requirements

- PHP `8.4` or newer, 64-bit, with the extensions required by the server
- Axolotl-PM with the API declared by `plugin.yml`
- `phar.readonly=0` only when building PHARs

See [runtime validation](docs/runtime-validation.md) for the tested server and PHP versions. DevTools is an independent community project.

## Minimal layout

All paths below are relative to the server directory.

| Path | Contents |
| --- | --- |
| `plugins/DevTools.phar` | Installed DevTools plugin |
| `plugins/MyPlugin/plugin.yml` | Plugin manifest |
| `plugins/MyPlugin/src/` | Plugin source |
| `plugins/MyPlugin/resources/` | Optional plugin resources |
| `plugins/MyPlugin/devtools.yml` | Optional build and virion declarations |
| `virions/ExampleVirion/` or `virions/ExampleVirion.phar` | Local library with `virion.yml` and `src/` |
| `build/` | Generated PHARs |

If your plugin uses a shared library, put the package in `virions/ExampleVirion/` and declare it for both development loading and the standalone build.

```yaml
# plugins/MyPlugin/devtools.yml
virions:
  - name: ExampleVirion
    version: ^1.0.0
```

See [shared virions](docs/shared-virions.md) for package requirements and version selection. The [HelloShared and SharedGreeting example](examples/README.md) demonstrates the complete workflow.

## Build on every commit

The reusable workflow supports push and pull-request builds. Use the [step-level action](docs/github-actions.md#step-level-action) for extra setup or checkouts. PHPStan is off unless you select a level and server source. A complete example lives in `examples/.github/workflows/build.yml`.

## Ecosystem

The repository includes an inventory of public repositories whose default-branch
workflows reference DevTools. See the [ecosystem inventory](docs/ecosystem.md)
for the summary, canonical JSON data and detection method.

- [BedrockNexus](https://bedrocknexus.com/) provides an official [Axolotl-PM workflow template](https://github.com/BedrockNexus/plugin-workflows/blob/main/templates/axolotl.yml) powered by DevTools.

## Build with a coding agent

Use the [coding agent guide](docs/ai-agent.md) for CLI automation or GitHub workflow setup. An [`AGENTS.md` template](examples/agent/AGENTS.md) is included in the examples ZIP.

## Commands

The command keeps the legacy `devtools.command` permission for compatibility. Its handler also recognizes the corresponding command-specific permission. The legacy permission remains an allow-all override for existing installations. Operators receive the permissions by default.

```text
/devtools status
/devtools doctor [PLUGIN]
/devtools virions
/devtools build [plugin|virion|both] PROJECT [--overwrite]
/devtools extract PHAR [--format=folder|zip] [--type=auto|plugin|virion] [--overwrite]
```

Migration aliases `/makeplugin` and `/extractplugin` are also available.

`doctor`, `build` and `extract` run as one-at-a-time background jobs on a dedicated worker thread, so they do not block the server tick thread or consume Axolotl-PM's central async task pool. A second heavy operation is rejected while one is running. To run DevTools only as a production folder and virion loader, disable these commands in `config.yml`:

```yaml
commands:
  doctor: false
  build: false
  extract: false
```

## Documentation

### Start here

- [Installation, update, rollback, and uninstall](docs/installation.md)
- [Folder plugin behavior](docs/plugin-directories.md)
- [Building and validating PHARs](docs/building.md)
- [Troubleshooting](docs/troubleshooting.md)

### Choose an advanced workflow

- [CLI commands and versioned JSON contract](docs/cli.md)
- [Local and locked Composer dependency preparation](docs/dependencies.md)
- [Clean Axolotl-PM runtime verification](docs/runtime-validation.md)
- [Configuration reference](docs/configuration.md)
- [Shared virions and version resolution](docs/shared-virions.md)
- [Building a PHAR on every commit with GitHub Actions](docs/github-actions.md)
- [Building a PHAR with a coding agent](docs/ai-agent.md)
- [FAQ](docs/faq.md)
- [Migration guide](docs/migration.md)

### Maintain DevTools

- [Dependency ownership and packaging policy](docs/dependency-policy.md)
- [Developer workflow](docs/development.md)
- [Architecture](ARCHITECTURE.md)
- [Security policy](SECURITY.md)

## Production guidance

Use folder plugins and shared virions for development, or disable the heavy server commands when DevTools is installed on a production server only for loading. Deploy tested PHARs to production. Editing source in place can leave partially changed code after a restart. For a project containing both `plugin.yml` and `virion.yml`, use `build both` to create two role-specific PHARs. DevTools never merges a plugin into a virion or a virion into another virion. Plugin-to-virion injection is rejected because a plugin manifest is not a virion contract.

## License and provenance

DevTools is MIT licensed. The bundled `nikic/php-parser` uses BSD-3-Clause. See [third-party notices](THIRD_PARTY_NOTICES.md) for its license.
