# DevTools 4.0.4

DevTools 4.0.4 fixes Composer dependency preparation when DevTools builds itself in automated workflows.

## Fixed

- Keep DevTools' locked Composer packages available as build assets without treating them as virions during its own build.
- Accept full-SHA-pinned first-party GitHub Actions while requiring checkout credentials to remain disabled.

## Compatibility

- Existing commands, manifests, workflow inputs and public runtime APIs are unchanged.
- PHP 8.4 remains the minimum supported version. CI covers PHP 8.4 and 8.5.

## Upgrading

- Projects using Composer-prepared BedrockNexus workflows should update to this release to build DevTools correctly.
- Other projects do not need configuration changes.

The release tag and checksum manifest are signed with the maintainer's GPG key. Verify `SHA256SUMS.txt.asc` before checking the downloaded assets against `SHA256SUMS.txt`.
