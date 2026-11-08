# DevTools 3.3.0

DevTools 3.3.0 makes Composer dependencies easier to use during local plugin development while preserving strict, reproducible PHAR builds.

## Highlights

- Load a matching local virion source folder from `virions/` when a plugin's `composer.lock` declares that Composer package.
- Prefer a verified Composer-prepared package when one is available, while supporting mixed prepared and local dependency graphs.
- Keep version constraints and shared dependency conflicts strict. Local package metadata, autoloading, antigen, source layout and PHP compatibility must match the lock.
- Add `virions.allow-local-composer` to disable local Composer source folders and require prepared packages at server startup.
- Keep PHAR builds and `doctor` checks strict: dependencies must still be prepared with verified provenance.
- Translate local Composer-source diagnostics and shared-virion loading summaries across all 14 supported Axolotl-PM locales.

## Compatibility and upgrade notes

- This is an additive release for the existing v3 workflow channel. Existing non-Composer local virions continue to work.
- Local Composer source loading is enabled by default for server development. Set `virions.allow-local-composer: false` to require prepared packages.
- No dependency is downloaded during server startup. CI and release builds continue to use prepared, lock-verified dependencies.
- The CLI JSON schema and PHAR build or extraction formats are unchanged.
