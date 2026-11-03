# AGENTS.md

## Repository map

- Read `PROJECT_MAP.md` first.
- `src/Loader/` owns folder discovery and loading.
- `src/Virion/` owns manifests, discovery, conflicts and class-loader registration.
- `src/Build/` owns declarations, staging, AST shading, PHAR creation and extraction.
- `src/Validation/` owns reusable plugin validation results.
- `src/Command/` handles interaction and orchestration only.
- `src/Support/` contains small dependency-free utilities.
- `tests/` mirrors source modules. Reusable projects live in `tests/Fixtures/`.

## Dependency rules

- Commands contain no loading, shading, archive, or validation algorithms.
- Build code must work without a running `Server`.
- PocketMine runtime calls stay in `DevTools`, Loader, the registrar, and Command.
- Support must not depend on business modules.
- Loader owns folder plugins. Virion owns shared libraries. Build owns artifacts.
- Do not add circular dependencies or a service locator.
- Add interfaces only at real external boundaries (currently class-loader registration and archive reading).

## Build and test commands

```text
composer install
composer test:loader
composer test:virion
composer test:build
composer test
composer phpstan
composer lint
composer build
composer artifact
composer check
```

PHAR tests and builds must use `phar.readonly=0`. Composer scripts already do this.

## Coding conventions

- PHP 8.4 baseline, `declare(strict_types=1);`, PSR-4.
- Typed properties and explicit parameter and return types.
- Constructor injection for dependencies. Use final classes by default.
- No `eval`, shell construction, error suppression, hidden network access, or mutable global registry.
- Reject unsafe ambiguity with an actionable message.
- Never follow symlinks while staging or extracting.
- Keep exclusions component-aware. Do not use loose substring matching.
- Preserve user source and existing artifacts on failure.

## Scope boundaries

Implement behavior that directly supports these workflows.

1. Folder plugin loading.
2. Shared development virion loading.
3. Standalone plugin and virion PHAR building and extraction, including dual-role projects and declared virions.

No hot reload, downloader, registry, marketplace, web UI, database, Composer replacement, publishing, telemetry, or universal fork framework.

## Definition of done

- Add behavior tests and failure-path tests with the change.
- Run focused tests first, then `composer qa`.
- If archive or shading code changes, run `composer artifact` to inspect a produced PHAR.
- If runtime loader code changes, run a compatible-server smoke test when practical.
- Documentation must describe only verified behavior.
- Review correctness, path security, namespace collisions, diagnostics, scope, and licensing.

## Context discipline

1. Read PROJECT_MAP.md.
2. Identify the module that owns the requested behavior.
3. Read that module and its tests.
4. Expand to adjacent modules only when an actual dependency requires it.
5. Do not read or refactor the entire repository for a local change.
6. Keep ordinary changes within one or two modules.
7. Run the focused tests first, then the complete quality suite.
