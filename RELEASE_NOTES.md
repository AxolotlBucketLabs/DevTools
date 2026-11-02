# DevTools 1.0.19

DevTools loads folder plugins and shared development virions on Axolotl-PM, builds standalone plugin and virion PHARs, and provides matching CLI, server-command and GitHub Actions workflows.

## Changes

- Add standalone virion PHAR builds with SHA-256 validation through the CLI, `/devtools build virion` and `build-virion.yml`.
- Detect plugin, virion and dual-role projects. A dual-role project produces separate `<PluginName>-plugin.phar` and `<VirionName>-virion.phar` artifacts.
- Prefer same-name virion source folders over packaged PHARs and report skipped packaged copies with an actionable warning.
- Convert plugin and virion PHARs to validated folders or ZIP archives from the CLI and server command.
- Extend the composite action and reusable workflow metadata with artifact type, name and version.
- Detect plugin folder/PHAR duplicates and document the upstream Axolotl-PM loader-order limitation.

## Install

Download `DevTools-1.0.19.phar` and verify its SHA-256 with `SHA256SUMS.txt`. Stop the server, place the file at `plugins/DevTools.phar`, start the server, and run `/devtools status`.

The examples ZIP contains the HelloShared plugin and SharedGreeting library. Follow the [example guide](https://github.com/NhanAZ/DevTools/blob/v1.0.19/examples/README.md) to load source and build a standalone PHAR.

## Compatibility and verification

PHP 8.4 remains the source, CLI and build minimum. Installing the source checkout's development dependencies requires the compatible Axolotl-PM PHP extensions.

The release candidate gate runs PHPStan max, syntax, style, unit tests, documentation checks and artifact validation. The full CI quality matrix runs on PHP 8.4 and PHP 8.5. The [runtime validation guide](https://github.com/NhanAZ/DevTools/blob/v1.0.19/docs/runtime-validation.md) covers folder plugins, shared virions and standalone PHARs on Axolotl-PM 5.50.0 with Windows x64 and PHP 8.4.24 ZTS. These tests use RakNet and do not test NetherNet client connections or gameplay.

DevTools 1.0.18 remains available for rollback. Runtime evidence is recorded separately from candidate metadata; CI on PHP 8.5 does not claim an Axolotl-PM server test on PHP 8.5.

## Release assets

- `DevTools-1.0.19.phar` contains the installable plugin.
- `DevTools-examples-1.0.19.zip` contains examples, a build workflow and a coding agent template.
- `RELEASE_NOTES.md` describes this release.
- `candidate.json` identifies the source commit, build run and asset hashes.
- `SHA256SUMS.txt` lists checksums.

## Documentation

- [Installation](https://github.com/NhanAZ/DevTools/blob/v1.0.19/docs/installation.md)
- [CLI and JSON](https://github.com/NhanAZ/DevTools/blob/v1.0.19/docs/cli.md)
- [Dependency support](https://github.com/NhanAZ/DevTools/blob/v1.0.19/docs/dependencies.md)
- [GitHub workflows](https://github.com/NhanAZ/DevTools/blob/v1.0.19/docs/github-actions.md)
