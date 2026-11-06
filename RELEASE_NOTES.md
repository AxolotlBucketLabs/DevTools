# DevTools 3.0.0

This major release moves DevTools to the AxolotlBucketLabs organization and renames its PHP namespace to `AxolotlBucketLabs\DevTools`.

## Breaking changes

- Update GitHub Actions references from `NhanAZ/DevTools` to `AxolotlBucketLabs/DevTools`.
- Update direct PHP imports from `NhanAZ\DevTools` to `AxolotlBucketLabs\DevTools`.
- The maintained workflow channel is now `v3`; the `v2` channel remains available at its prior release for consumers that need a staged migration.

## Compatibility

- CLI and GitHub Actions builds remain synchronous, with the v3 workflow contract matching the v2 build inputs and outputs.
- PHAR creation still requires PHP to run with `phar.readonly=0`; loading an existing PHAR does not.
