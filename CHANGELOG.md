# Changelog

## Unreleased

- Move startup dependency collection out of the plugin entry point and replace loader failure callbacks with a typed per-project index.
- Separate candidate selection, graph preflight and cycle analysis from shared virion registration while preserving folder precedence, compatible cycles and ordered failures.
- Share project doctor checks between CLI preflight and server commands, and replace the worker's dependency lookup array with typed properties.
- Use one artifact installer for plugin, virion and paired builds, and one prepared-content verifier for dependency selection and virion packaging.
- Move dual-role project validation and artifact naming into `DualArtifactBuilder`. Direct PHP callers must prepare a `DualArtifactProject` before building and update the builder and CLI constructors. See [migration](docs/migration.md#direct-php-integrations).
- Separate CLI operation branches while keeping failure phases, output data and the existing command and workflow syntax.
- Remove duplicate class checks from build and runtime callers while retaining scanner validation and runtime symbol checks.
- Use one translated dual-role rejection message for CLI and server commands across all 14 locales.

## 3.3.1 - 2026-11-10

- Complete the 14 locale catalogs and localize the remaining user-facing CLI and runtime messages.
- Clarify the runtime startup phases without changing the v3 build workflow or dependency contract.
- Align the CLI, ecosystem and GitHub Actions documentation with the current v3 interfaces and placeholder syntax.
- Align PHP formatting with PocketMine-MP conventions and add a dedicated code style workflow.

## 3.3.0 - 2026-11-09

- Accept matching local Composer virion source folders during shared server development, including dependencies whose Composer and manifest names differ.
- Preserve exact locked versions, shared conflicts and prepared-package verification, including mixed local and prepared dependency graphs.
- Add `virions.allow-local-composer` to require prepared packages at startup when disabled. Build and doctor verification continue to require preparation.
- Recommend Composer for new projects while retaining existing local virion workflows.
- Translate local Composer-source diagnostics and shared-virion loading summaries into all 14 supported languages, and include translation tests in the default QA suite.

## 3.2.0 - 2026-11-08

- Add bundled translations for 14 Axolotl-PM locales and let server output follow the selected server language by default.
- Add explicit server configuration, CLI `--lang` and `DEVTOOLS_LANG` support for choosing the human-facing language.
- Localize lifecycle messages, command summaries, common build and extraction results and CLI help while keeping detailed technical diagnostics in English.
- Package the translation catalogs in the PHAR and verify locale coverage through the test suite and runtime smoke checks.
- Keep the existing v3 workflow contract, dependency resolution and build behavior unchanged when no language override is configured.

## 3.1.0 - 2026-11-08

- Add opt-in DEBUG diagnostics for server startup, folder plugin discovery, shared virion resolution and loading, PHAR building, extraction, and background jobs.
- Add the CLI `--debug` option while keeping diagnostics on stderr so the versioned JSON output remains machine-readable.
- Document the `logging.debug` server setting and keep DEBUG disabled by default without changing normal loading, validation or build behavior.
- Refresh the locked Symfony Filesystem dependency to `v6.4.50`, which includes its upstream security fix.

## 3.0.0 - 2026-11-06

- Transfer project ownership to the AxolotlBucketLabs organization and update maintained links, workflow references and release metadata.
- Rename the DevTools PHP namespace from `NhanAZ\DevTools` to `AxolotlBucketLabs\DevTools`.
- Publish the breaking `v3` workflow channel and preserve the existing `v2` release line for consumers that have not migrated yet.

## 2.1.1 - 2026-11-04

- Allow Composer virions to explicitly exclude consumer-owned package-root resources while preserving resources colocated with their source tree.
- Validate the new `extra.virion.exclude-root-resources` setting and cover the Composer preparation path with regression tests.

## 2.1.0 - 2026-11-04

- Run server-side `doctor`, build and extraction commands on a dedicated worker thread instead of the server tick thread or PocketMine's central async task pool.
- Reject concurrent heavy server operations, preserve artifacts until a complete operation succeeds and allow production servers to disable the heavy commands through `config.yml`.
- Add command-specific permission checks while preserving the legacy `devtools.command` permission.

## 2.0.2 - 2026-11-04

- Reject virion classes declared only in `.inc` or `.phtml` files when no matching `.php` autoload path exists.
- Pin first-party GitHub Actions to immutable SHAs and disable checkout credential persistence in every maintained workflow.

## 2.0.1 - 2026-11-03

- Let the composite action install its own builder and prepared Composer dependencies without requiring the optional `ext-webrtc` extension.

## 2.0.0 - 2026-11-02

- Remove the obsolete `devtools-ref` reusable-workflow input; builder identity now always follows the called workflow revision.
- Let GitHub repository and organization policy control Actions artifact retention instead of imposing a DevTools-specific default.
- Keep consumer builds eligible to finish for every push and pull request by removing template cancellation of in-progress runs.
- Add migration guidance for v1 workflow callers.

## 1.0.19 - 2026-11-02

- Add standalone virion PHAR building through the CLI, server command and reusable GitHub Actions workflow.
- Add plugin, virion and dual-role project detection. Dual-role projects build as separate plugin and virion artifacts.
- Prefer virion source folders over same-name PHARs and report packaged duplicates as skipped with a warning.
- Add PHAR extraction to folders or ZIP archives for both plugin and virion artifacts.
- Extend the composite action and reusable workflow metadata contract with plugin/virion artifact identity.
- Detect folder/PHAR plugin duplicates and document the Axolotl-PM loader-order limitation without altering upstream loader state.

## 1.0.18 - 2026-10-20

- Verify Axolotl-PM 5.50.0 and synchronize the development dependency, CI and compatibility monitor with source commit `fe5b3dee173b5e839783ab6fb7280005aa0a4d73`.
- Update the locked server dependencies to BedrockProtocol 63.0.1 and NetherNet 0.3.1. Development installations now require `ext-webrtc`.
- Keep runtime smoke servers on loopback RakNet and add regression coverage for workflow pins drifting from the lockfile.

## 1.0.17 - 2026-10-20

- Reject linked source paths, including Windows directory junctions, without traversing them during staging or cleanup.
- Preserve existing output directories and links on build failure, reject resource and metadata collisions with bundled virions, and retain supported license and notice files.
- Handle numeric-leading package names and attributed anonymous classes correctly when preparing namespaces and inspecting source.
- Close shading guard gaps involving imported aliases, named arguments and argument unpacking while preserving supported static references.
- Apply semantic version precedence consistently when selecting virions, including prereleases, build metadata and large version components.
- Reject empty plugin API lists, invalid source paths and malformed release tags with clear diagnostics; support qualified server command aliases.
- Remove duplicate validation and unused imports, align PHPStan with PHP 8.4, expand regression coverage and shorten documentation.

## 1.0.16 - 2026-10-20

- Raise DevTools' minimum PHP version to 8.4 and run the full CI quality gate on PHP 8.4 and 8.5.
- Use PHP 8.4 by default in the reusable build and publication workflows.
- Generate prepared Composer virion metadata with DevTools' PHP 8.4 baseline.

## 1.0.15 - 2026-10-14

- Require a packaging identity string compared with `__CLASS__` to exactly match the original enclosing class name. This prevents an unrelated namespace prefix from bypassing the standalone namespace reference guard.
- Preserve exact identity sentinels through post-shading validation by resolving shaded namespaces back to their original names.
- Add regression coverage for unrelated namespace prefixes and case-mismatched identity strings.

## 1.0.14 - 2026-10-14

- Allow dynamic construction from a local static class map whose values are explicit `::class` references immediately selected by the factory.
- Preserve exact `__CLASS__ !== 'original\\Class'` packaging sentinels used to detect namespace shading while continuing to reject unrelated identity strings.
- Keep mutable, runtime-supplied and otherwise unproven dynamic class maps rejected.

## 1.0.13 - 2026-10-14

- Allow object-class diagnostics inside the same short-circuit `instanceof ... || throw` guard when the inspected variable is the guarded operand.
- Keep dynamic class factories, static calls, computed targets and mismatched guarded variables rejected.

## 1.0.12 - 2026-10-13

- Allow only variable class operands of `instanceof`, which perform type checks without autoloading; computed targets remain rejected.
- Rewrite the exact `Fiber::getCurrent()`, non-null guard and `suspend()` pattern to `\Fiber::suspend()`; other dynamic static calls remain rejected.
- Rewrite `ReflectionClass($className)` only when the immediately preceding statement assigns that variable from `get_called_class()` inside a non-static closure with a bound object. Also allow `ReflectionClass($this)` where `$this` is available.
- Keep regression tests for each accepted pattern and for similar unproven patterns that must remain rejected.

## 1.0.11 - 2026-10-13

- Allow dynamic `is_a()` target types when `allow_string` is omitted and the target is a variable. PHP compares that target without autoloading it; calls with an explicit third argument remain subject to the dynamic-reference guard.
- Add regression tests for the allowed comparison and the rejected autoload-enabled form.

## 1.0.10 - 2026-10-11

- Prepare locked Composer virions when a PHP file declares its matching class and additional classes in the same namespace and directory. Generate a small autoload shim for each additional class and record the mapping in provenance.
- Keep standalone classes with incorrect paths rejected, detect duplicate class declarations regardless of letter case, and retain the existing dynamic class shading guard.

## 1.0.9 - 2026-10-11

- Require annotated runtime class factories to have two unconditional throwing guards immediately before a direct return of the new object.
- Reject unreachable checks and a class name reassigned after validation. The previous scan could mistake either case for a safe shaded factory.
- Keep the existing explicitly guarded factory pattern and reject unproven dynamic class operations.

## 1.0.8 - 2026-10-11

- Prepare Composer virions from `dev-` branches only when `composer.lock` pins a full Git commit and source and distribution references agree. Give each prepared revision a distinct internal version and keep the original Composer version in provenance.
- Match internal commit versions by exact revision because PHP version comparison can consider different hexadecimal suffixes equal.
- Add `import-poggit-libraries: false` for projects that keep legacy Poggit declarations but use their reviewed Composer lock as the DevTools dependency source. The default still imports Poggit libraries.
- Keep dynamic class shading guards in place. Preparation alone does not certify a standalone PHAR.

## 1.0.7 - 2026-10-11

- Require an unannotated object-class return to follow a top-level `instanceof ... || throw ...` guard immediately in the same function.
- Reject guards inside optional branches and guards invalidated by an intervening assignment. Keep the explicit annotation path and other 1.0.6 dependency preparation behavior.
- Derive the Axolotl-PM runtime smoke target from the committed Composer lock and align the reproduction guide with the current 5.49.1 pin.

## 1.0.6 - 2026-10-11

- Initially allow an object-class expression after an explicit `instanceof ... || throw ...` guard; version 1.0.7 tightens the control-flow requirement.
- Add a regression test for guarded object-class inspection without weakening rejection of unproven dynamic class operations.

## 1.0.5 - 2026-10-11

- Permit explicitly annotated, guarded runtime class factories after `class_exists()` and `is_subclass_of()` prove the selected class is a supported implementation.
- Permit explicitly annotated object-class inspection such as `$callback::class` while continuing to reject unproven dynamic class references and operations.
- Add regression coverage and document the annotation contract for consumers that use safe runtime class selection.

## 1.0.4 - 2026-10-11

- Prepare locked Composer virions whose PSR-4 or namespace-based PSR-0 mapping uses a one-element directory array.
- Prepare single-root classmap packages only when their PHP source stays inside the declared namespace tree and passes the existing class-layout validation.
- Keep root plugin classmaps, multiple package roots, dynamic class references during shading and unverified dependency source changes rejected.

## 1.0.3 - 2026-10-10

- Check out the build and release verification tools from the exact commit that defines each reusable workflow job.
- Let Dependabot update one reusable workflow reference without a second revision input becoming stale.
- Keep `devtools-ref` as a deprecated, ignored input for existing callers; warn when it is provided.

## 1.0.2 - 2026-10-10

- Let the reusable plugin build workflow check out a full, pinned Axolotl-PM source commit for PHPStan analysis.
- Inspect the PHAR archive before the reusable workflow uploads its artifact.
- Exercise the reusable workflow with a fixture plugin, PHPStan maximum level and the pinned Axolotl-PM source in hosted CI.
- Keep the step-level action and existing workflow inputs compatible with version 1.0.1.

## 1.0.1 - 2026-10-10

- Treat `pocketmine/pocketmine-mp` as the server platform only when the locked Axolotl-PM package explicitly replaces it. Continue rejecting missing or unrelated packages.
- Accept a locked Composer virion's `php-64bit` requirement and check the PHP integer size during preparation and shared loading.
- Keep `composer-runtime-api` as an installation constraint checked by Composer. It is not a plugin runtime dependency and is omitted from generated virion manifests.
- Add regression tests for the Composer replacement and platform requirements.

## 1.0.0 - 2026-10-10

Initial release for Axolotl-PM.

- Load folder plugins through the server's plugin manager.
- Load compatible local virions with transitive dependency and collision checks.
- Build standalone PHARs with private AST shading, resources, licenses and output protection.
- Inspect and extract PHARs with archive validation.
- Prepare supported Composer Virion v3 packages from an installed, locked graph.
- Provide CLI commands with versioned JSON, diagnostic codes and artifact hashes.
- Build and publish plugin artifacts through GitHub workflows with optional PHPStan.
- Include a dedicated example plugin, library and coding agent template.
