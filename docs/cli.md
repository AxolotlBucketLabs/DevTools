# CLI and JSON contract

The CLI uses the same validation, dependency selection, builder and extractor as the Axolotl-PM plugin. Run it from a DevTools source checkout. The server PHAR is not an executable CLI bundle.

## Install and run

Use PHP 8.4 or newer with the extensions required by the locked Axolotl-PM packages, including YAML, and Composer. Install DevTools's locked dependencies including development packages. The CLI uses Axolotl-PM's manifest types without starting a server.

```sh
composer install --no-scripts --no-plugins
php bin/devtools.php --help
php bin/devtools.php --version
php bin/devtools.php doctor --project=/path/to/MyPlugin
php -d phar.readonly=0 bin/devtools.php build --project=/path/to/MyPlugin
php -d phar.readonly=0 bin/devtools.php build --type=virion --project=/path/to/SharedGreeting
php -d phar.readonly=0 bin/devtools.php build --type=both --project=/path/to/DualProject
php bin/devtools.php inspect --project=/path/to/MyPlugin --artifact=build/MyPlugin.phar
php bin/devtools.php extract --artifact=/path/to/MyPlugin.phar --out=/path/to/extracted
php bin/devtools.php extract --artifact=/path/to/SharedGreeting.phar --out=build/SharedGreeting.zip --type=virion --format=zip
php bin/devtools.php doctor --project=/path/to/MyPlugin --debug
```

## Paths and options

`--project` defaults to the current directory and resolves relative to it. All other relative paths resolve from that project, regardless of option order. Defaults are `PROJECT/virions` and `PROJECT/build`. Use an absolute path for a server's shared virions directory.

`--out` selects an extraction directory for `--format=folder` and a `.zip` filename for `--format=zip`. Build `--out` remains a directory. Options use `--name=value`. Unknown, duplicate path and command-inapplicable options fail. Commands never prompt for input. Replacement requires `--overwrite`.

Add `--debug` to print detailed lifecycle and dependency diagnostics to stderr. It is disabled by default and never changes the JSON schema or command result. Debug output is intentionally separate from stdout so `--json` remains machine-readable.

`build --type=plugin` is the default and requires `plugin.yml`. `build --type=virion` requires `virion.yml`. `build --type=both` requires both manifests and writes two role-specific artifacts. A plugin build injects declared virions by AST shading. It does not accept a plugin as a virion dependency. `extract --format=folder` is the default, and writes files directly under the resolved `--out` directory. It does not infer a `plugins/` or `virions/` destination for the CLI. That convention belongs to the server command. `extract --format=zip` writes a safe ZIP without executing archive code.

For supported Composer libraries, install from the reviewed plugin lockfile before preparation.

```sh
composer install --working-dir=/path/to/MyPlugin --no-dev --no-scripts --no-plugins --no-autoloader
php bin/devtools.php prepare --project=/path/to/MyPlugin --virions=prepared
php -d phar.readonly=0 bin/devtools.php build --project=/path/to/MyPlugin --virions=prepared
```

`prepare` imports installed, locked packages into a new directory. It refuses to replace an existing dependency directory. It does not download packages, update the lockfile, execute vendor autoload code or run Composer. See [dependency support](dependencies.md) for the accepted formats. A plugin without dependencies needs no preparation step.

## What each command checks

| Command | Checks | Does not establish |
| --- | --- | --- |
| `doctor` | Manifest, main source structure, declarations and local dependency selection | Complete shading, static analysis or server boot |
| `prepare` | Supported locked package graph, installed metadata and import structure | Independent authenticity of vendor bytes or server boot |
| `build` | Plugin or virion validation, optional AST shading, archive content and SHA-256 | Server boot or gameplay behavior |
| `inspect` | Manifest, main entry, signature and whole-file SHA-256 without running the PHAR stub | Publisher identity or safe executable code |
| `extract` | Transactional extraction and archive path validation | That the extracted project boots |

SHA-256 checks integrity. It does not authenticate the publisher. Intentional external plugin dependencies must still be installed on Axolotl-PM.

`doctor` checks the selected project's dependency graph. It does not establish compatibility with all source plugins on a server. Use `/devtools virions` for the server's shared registry and read the server log for enable failures.

## JSON schema version 1

Add `--json` to any command, `--help` or `--version`. Stdout contains exactly one JSON document and a newline. Progress and PHP diagnostics use stderr. Structured diagnostics remain in the JSON document. Check both the exit status and `success`, and accept additional fields within schema version 1.

The [JSON Schema](cli.schema.json) defines these fields.

| Field | Meaning |
| --- | --- |
| `schema_version` | Integer `1` |
| `success` | Boolean indicating whether the operation completed |
| `command` | Operation name, or `bootstrap` when dependencies cannot be loaded |
| `tool` | Object with `name` and `version`. Version comes from `plugin.yml` and is null only when bootstrap cannot load dependencies |
| `data` | Command result object |
| `diagnostics` | List of objects with `code`, `severity`, `message`, `file`, `line` and `suggestion`. File, line and suggestion may be null |
| `checks` | Status of `structure`, `dependencies`, `artifact`, `static_analysis` and `runtime`. Each is `passed`, `failed` or `not_run` |

Plugin build and inspect results include `artifact`, `type: plugin`, `sha256`, `plugin`, `files` and `signature`. Virion results use `type: virion` and a `virion` object with `name`, `version` and `antigen`. Dual builds return `artifacts`, one inspected object for each role. Plugin builds also return `dependencies` and `shaded_namespaces`. Prepare returns `directory` and `dependencies`. Extract returns `destination`, `format` and `files`. A successful doctor result returns `project`, `plugin` and `dependencies`.

Validator codes such as `plugin.manifest_missing` identify specific project problems. Operation failures use `operation.build_failed`, `operation.doctor_failed`, `operation.prepare_failed`, `operation.inspect_failed` or `operation.extract_failed`. Argument errors use `cli.usage`. Missing tooling dependencies use `cli.environment`. Read `message` for the cause without parsing exception substrings.

An argument error after a recognized command retains that command. For example, `build --unknown` reports command `build`, code `cli.usage` and exit status `2`. CLI commands never mark static analysis or runtime as passed. A failed operation can retain earlier passed checks.

| Exit code | Meaning |
| --- | --- |
| `0` | Success |
| `1` | Validation, operation or environment failure |
| `2` | Invalid CLI usage |

```sh
php -d phar.readonly=0 bin/devtools.php build --project=/path/to/MyPlugin --json
```

Read `data.artifact` rather than deriving an output filename. Compare `data.sha256` with the actual file bytes.

## Coding agent workflow

1. Read help and version.
2. Run doctor and resolve reported project or environment problems.
3. Prepare dependencies explicitly when needed. A missing package is not a reason to guess its repository.
4. Build with `--json`.
5. Inspect the returned artifact and verify its hash.
6. Report static analysis and [runtime validation](runtime-validation.md) separately.

## Legacy build entry point

`bin/devtools-build.php` adapts legacy build flags and accepts `--json`, `--help` and `--version`. It preserves its original project, output and virion defaults. Explicit relative paths resolve from the process working directory. The main CLI uses the project-relative rules above.

Scripts should consume JSON rather than scrape human-readable build messages.
