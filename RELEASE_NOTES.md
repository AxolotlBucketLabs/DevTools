# DevTools 4.0.0

DevTools 4.0.0 reorganizes dependency selection, runtime loading and artifact building. Direct PHP integrations need the migration described below. Plugin commands, workflow inputs, manifest formats and CLI JSON schema remain unchanged.

## Changes

- Keep startup dependency errors scoped to the affected folder plugin.
- Separate candidate selection and graph checks from class-loader registration while preserving folder precedence, compatible cycles and ordered diagnostics.
- Share artifact replacement and rollback across plugin, virion and dual builds.
- Share prepared-content verification and project doctor checks, and retain preflight data when a CLI build fails.

## Upgrading

- Replace `plugins/DevTools.phar` and restart the server.
- Use `AxolotlBucketLabs/DevTools@v4` or the corresponding reusable workflow reference for the 4.x channel. `v3` remains on the 3.x series. `latest` follows stable releases across major versions.
- Direct PHP callers must update the dual builder, CLI and folder-loader construction described in the [migration guide](https://github.com/AxolotlBucketLabs/DevTools/blob/v4.0.0/docs/migration.md#direct-php-integrations).
- PHP 8.4 remains the minimum. Quality checks cover PHP 8.4 and 8.5. Real-server smoke tests use Axolotl-PM 5.51.1 on PHP 8.4.27.

The release tag and checksum manifest are signed with the maintainer's GPG key. Verify `SHA256SUMS.txt.asc` before checking the downloaded assets against `SHA256SUMS.txt`.
