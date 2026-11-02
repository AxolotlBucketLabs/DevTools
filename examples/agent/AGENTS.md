# AGENTS.md

## DevTools PHAR workflow

- For CLI, dependency and release interfaces, read `docs/cli.md`, `docs/dependencies.md`, and `docs/github-actions.md` from the exact DevTools revision used. The references below select release 1.0.19.
- Use CLI `--json`, check the exit status and `success`, consume the returned artifact path and verify its SHA-256. Report runtime testing and static analysis separately from package validation.

- Read `plugin.yml`, `devtools.yml`, Composer metadata, existing workflows, and referenced source before changing the build.
- Use `NhanAZ/DevTools/.github/workflows/build-plugin.yml` at the full commit resolved from `v1.0.19` according to the versioned [GitHub Actions guide](https://github.com/NhanAZ/DevTools/blob/v1.0.19/docs/github-actions.md). The workflow checks out the builder from its own commit. Use the step-level action when extra setup is required.
- Run the PHAR workflow on `push`, `pull_request`, and `workflow_dispatch` with `contents: read` permission.
- The reusable workflow owns the documented Node.js 24 compatible Axolotl-PM PHP setup. Use the step-level setup only when that workflow is selected.
- Keep PHPStan off unless this repository explicitly enables it. When enabled, keep the requested level and pin Axolotl-PM source with `axolotl-ref`. Use `phpstan-server` only for additional source paths already checked out by the caller.
- Pass external plugin or library API source directories through `phpstan-paths`. Do not combine server forks there.
- Pin external checkouts to exact commit SHAs. Do not guess virion repositories, dependency revisions, or compatible server sources.
- Preserve unrelated workflow gates and user changes.
- Do not use Poggit CI, commit generated PHARs, publish a release, force-push, delete releases, suppress PHPStan failures, or change plugin source only to make CI pass.
- Upload exactly one artifact from the PHAR path returned by DevTools. Verify the inner `<PluginName>.phar`, shaded virion count, annotation count, and artifact count.
- Stop with an actionable report when an authoritative dependency source or compatible revision cannot be established from repository evidence.

## Required handoff

Report changed files, pinned revisions, PHPStan state and targets, the exact PHAR filename, workflow result, annotations, and downloadable artifact name.
