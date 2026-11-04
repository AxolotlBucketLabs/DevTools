# DevTools 2.1.1

This patch release keeps the v2 workflow contract while allowing Composer virions to describe resources owned by their consuming plugin.

## Added

- Composer virions may set `extra.virion.exclude-root-resources: true` when their package-root resources belong to the consuming plugin. DevTools continues to preserve resources colocated under the selected source directory.

## Compatibility

- CLI and GitHub Actions builds remain synchronous.
- PHAR creation still requires PHP to run with `phar.readonly=0`; loading an existing PHAR does not.
