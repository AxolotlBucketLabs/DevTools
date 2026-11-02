# Building standalone PHARs

## From the server

The same operations are available outside a running server through the [CLI](cli.md). [Composer preparation](dependencies.md) is explicit and occurs before the build. Neither normal build nor server startup downloads dependency code.

Run these commands as an operator.

```text
/devtools doctor MyPlugin
/devtools build MyPlugin
/devtools build virion SharedGreeting
/devtools build both MyDualProject
/devtools extract SharedGreeting.phar --type=virion --format=zip
```

The artifact is written to server-root `build/MyPlugin.phar`. Existing output is protected. Use `/devtools build MyPlugin --overwrite` only after confirming that replacement is intended.

`build virion <name-or-folder>` writes `build/<VirionName>.phar` from a source folder containing `virion.yml` and `src/`. `extract` detects plugin and virion PHARs automatically; use `--type` when a dual-role archive contains both manifests. `--format=folder` extracts to `plugins/` or `virions/`, while `--format=zip` writes a ZIP under `build/`. A dual-role project must be built with `build both`; it creates `<PluginName>-plugin.phar` and `<VirionName>-virion.phar` so each artifact has one unambiguous role.

Server `doctor` checks project structure, build declarations and that project's local dependency selection using the same resolver as the CLI. It does not prove that all installed plugins have compatible shared requirements or that a plugin enabled successfully. Use `/devtools virions` for the actual shared registry and the server log for enable failures. A doctor run does not perform shading, PHPStan or a runtime test.

Unknown or repeated server build and extract options are rejected before writing output. Declared virions are the supported injection boundary: plugin builds shade selected virions into a private namespace; virion builds preserve their own source and do not merge plugins or other virions. A plugin folder supplied as a virion is rejected by virion manifest validation.

Virion discovery prefers a source folder over a same-name PHAR, records the PHAR as skipped and logs the reason. For plugin duplicates, DevTools emits a warning when a folder and PHAR declare the same plugin name. Axolotl-PM 5.50 exposes only append-only loader registration, so its built-in PHAR loader can still win that plugin duplicate during server triage; remove the duplicate PHAR or verify the startup log before relying on the folder copy.

## What the builder validates

Before installing output, DevTools validates the plugin manifest and main class, local configuration, declared virions and versions, namespace safety, PHP syntax, and standalone references. It stages source without changing it, AST-shades virions into a deterministic plugin-private namespace, creates a SHA-256 PHAR, reopens it, and checks the manifest, main source, shaded roots, and signature.

The default package includes `plugin.yml`, `src/`, `resources/`, common license and notice files, declared virions, and explicit `include-paths`. It excludes `.git`, `.github`, IDE files, tests, caches, logs, `build/`, ordinary development `vendor/`, temporary files, and undeclared project files.

Builds reject unsafe dynamic class references, affected namespaces stored in strings, ambiguous grouped imports, undeclared development namespaces, and manifest dependencies on DevTools or DEVirion.

The shader accepts specific dynamic patterns whose behavior it can verify:

- The variable target type in a two-argument global `is_a()` call. Supplying a third argument keeps the call subject to the dynamic-reference guard.
- A factory annotated with `@devtools-allow-dynamic-class $variable`, where `return new $variable()` immediately follows top-level throwing `class_exists()` and `is_subclass_of(..., Interface::class)` guards. An annotated factory may also select from a local static map of explicit `::class` values immediately before returning.
- Annotated object-class expressions such as `match ($object::class)` or an error message containing `$object::class`. The annotation does not permit dynamic static operations.
- Unannotated `return $object::class` immediately after `$object instanceof StaticClass || throw ...` in the same function.
- Packaging sentinels in the exact form `__CLASS__ !== 'original\\Class'`.

Conditional guards, reassignment after validation, mutable class maps, and unrelated class-name strings do not satisfy these rules. Successful dependency preparation alone does not prove that source can be shaded.

## Building DevTools from source

Release users do not need Composer. Maintainers can run the complete build gate.

```text
composer install
composer check
```

To build and inspect the artifact without running the full gate, use these commands.

```text
composer build
composer artifact
```

The self-build is `build/DevTools.phar`. Official release automation renames it to `DevTools-<version>.phar` and publishes `SHA256SUMS.txt` after all checks pass.

DevTools currently has no declared runtime virions. Its self-build therefore ignores unrelated virions found in the server's `virions/` directory, so an installed development virion cannot change the result of `composer check`. Normal plugin builds still reject unproven dynamic class references when local virions are available.

## Production verification

Use the release checksum to verify a download.

```text
# Linux (run beside the downloaded release assets)
sha256sum -c SHA256SUMS.txt

# macOS
shasum -a 256 -c SHA256SUMS.txt

# Windows PowerShell
Get-FileHash .\DevTools-<version>.phar -Algorithm SHA256
```

For another plugin, test the generated PHAR on a clean compatible server without its source folder, DevTools, or shared virion directories. Exercise the plugin behavior that requires its bundled libraries and resources.
