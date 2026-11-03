# Project map

DevTools has three core workflows. It loads folder plugins, loads shared development virions and builds standalone plugin or virion PHARs, including separate artifacts for dual-role projects.

## Modules

| Module | Responsibility | Dependencies | Tests |
| --- | --- | --- | --- |
| `src/DevTools.php` | Start DevTools and connect its runtime modules | Axolotl-PM and the modules below | Module tests and runtime smoke tests |
| `src/Loader/` | Discover and load folder plugins | Validation, Support and Axolotl-PM loader APIs | `tests/Loader/` |
| `src/Virion/` | Read manifests, select compatible packages, detect conflicts and register shared classes | Support and Axolotl-PM class loader APIs | `tests/Virion/` |
| `src/Build/` | Resolve declarations, stage files, shade libraries, build and extract PHARs | Validation, Virion metadata, Support and `nikic/php-parser` | `tests/Build/` |
| `src/Validation/` | Read plugin manifests and produce reusable diagnostics | Support and Axolotl-PM manifest types | `tests/Validation/` |
| `src/Command/` | Handle server command arguments and report results | Public services from the modules above | `tests/Command/` |
| `src/Cli/` | Handle CLI arguments and report readable or versioned JSON results | Build and Validation public services | `tests/Cli/` |
| `src/Support/` | Provide filesystem, path, YAML and PHP source utilities | PHP and required extensions | Tests in the owning modules |

Build code works without a running server. Commands orchestrate services rather than duplicating their algorithms. Support does not depend on business modules.

## Entry points and tooling

| Path | Purpose |
| --- | --- |
| `bin/devtools.php` | Main CLI entry point |
| `bin/devtools-build.php` | Legacy build adapter with its original path defaults |
| `bin/devtools-phpstan-config.php` | Generate an isolated PHPStan configuration from explicit local inputs |
| `bin/runtime-smoke.php` | Run dedicated plugin and library fixtures on isolated Axolotl-PM servers |
| `bin/check-syntax.php` | Validate project PHP syntax |
| `bin/check-doc-links.php` | Check local documentation links and punctuation |
| `bin/validate-artifact.php` | Validate the actual DevTools PHAR |
| `bin/verify-version.php` | Verify synchronized release metadata |
| `action.yml` | Build an external plugin through the same CLI |
| `.github/workflows/build-plugin.yml` | Reusable plugin build workflow |
| `.github/workflows/build-virion.yml` | Reusable virion build workflow |
| `.github/workflows/release-plugin.yml` | Reusable plugin publication workflow |
| `.github/workflows/release-candidate.yml` | Build and validate one explicit DevTools candidate |
| `.github/workflows/release.yml` | Publish that candidate's verified original bytes |
| `plugin.yml` | Plugin identity, commands and permission |
| `devtools.yml` | Files included when building DevTools itself |
| `composer.json` and quality configuration files | Locked dependency and QA toolchain |
| `examples/` | Dedicated plugin and library examples, a build workflow and a coding agent template |
| `tests/Fixtures/` | Projects maintained by DevTools for behavior and failure-path tests |

Read [development](docs/development.md) for checks, [architecture](ARCHITECTURE.md) for module interactions and [runtime validation](docs/runtime-validation.md) for the smoke procedure.
