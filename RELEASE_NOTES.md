# DevTools 4.0.1

DevTools 4.0.1 makes repeated plugin and virion PHAR builds reproducible when staged files and the build toolchain are unchanged.

## Changes

- Use stable archive entry ordering and normalized metadata for plugin and virion PHAR builds.
- Retain SHA-256 PHAR signatures after archive metadata normalization.
- Declare the ZIP extension required by the PHAR build path.

## Compatibility

- Plugin commands, manifest formats, namespace contracts, and runtime APIs are unchanged.
- PHP 8.4 remains the minimum supported version. CI covers PHP 8.4 and 8.5.
- Build environments must provide the ZIP extension.

## Upgrading

- Replace `plugins/DevTools.phar` and restart the server.
- No source migration is required for plugin or virion consumers.

The release tag and checksum manifest are signed with the maintainer's GPG key. Verify `SHA256SUMS.txt.asc` before checking the downloaded assets against `SHA256SUMS.txt`.
