# DevTools 4.0.3

DevTools 4.0.3 hardens path validation, background build failures and localized diagnostics without changing the public command or manifest contracts.

## Fixed

- Preserve worker identifiers and typed responses when background jobs fail.
- Keep source paths, Windows roots, locale identifiers and Composer PHP constraints intact across build and validation flows.
- Reject unsafe virion paths, reserved Windows device names, duplicate manifest sections and external project paths.
- Improve namespace reference detection for dynamic strings, reflection properties and compound function guards.
- Preserve extracted files during rollback and create valid empty ZIP archives.
- Localize diagnostics consistently across the CLI, workers, runtime loader and all 14 supported locales.
- Clarify validation failures for PHPStan, YAML linting and unsupported PHP constraints.

## Compatibility

- Existing commands, manifests, workflow inputs and public runtime APIs are unchanged.
- PHP 8.4 remains the minimum supported version. CI covers PHP 8.4 and 8.5.

## Upgrading

- Replace `plugins/DevTools.phar` and restart the server.
- No manifest or workflow changes are required for supported projects.
- Projects using the existing DevTools workflows do not need configuration changes.

The release tag and checksum manifest are signed with the maintainer's GPG key. Verify `SHA256SUMS.txt.asc` before checking the downloaded assets against `SHA256SUMS.txt`.
