# DevTools 2.1.0

This feature release keeps the v2 workflow contract while moving expensive server-side maintenance commands off the server tick thread.

## Added

- Server-side `doctor`, `build` and `extract` operations now run as one-at-a-time background jobs on a dedicated worker thread. They no longer block the server tick thread or consume PocketMine's central async task pool.
- Concurrent heavy operations are rejected with an actionable message instead of being run simultaneously.
- Added command-specific permissions for `status`, `doctor`, `build`, `extract` and `virions`, while preserving the legacy `devtools.command` permission.
- Added `config.yml` switches for disabling `doctor`, `build` and `extract` on production servers that only need folder loading and shared virions.

## Compatibility

- CLI and GitHub Actions builds remain synchronous.
- PHAR creation still requires PHP to run with `phar.readonly=0`; loading an existing PHAR does not.
