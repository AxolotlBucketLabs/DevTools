# DevTools 4.0.2

DevTools 4.0.2 hardens YAML and archive processing and closes runtime, extraction and localization edge cases.

## Security

- Disable PHP object decoding when parsing YAML.
- Reject archives with more than 10,000 entries, any entry larger than 64 MiB, or more than 256 MiB of uncompressed content.

## Fixed

- Preserve background job results when errors contain invalid UTF-8.
- Report the resolved plugin or virion type during extraction.
- Tighten dynamic class annotation checks used by PHAR namespace shading.
- Complete runtime, virion, queue and extraction messages in all 14 locales.

## Compatibility

- Existing commands, manifests and workflow inputs are unchanged.
- PHP 8.4 remains the minimum supported version. CI covers PHP 8.4 and 8.5.
- Archives above the new processing limits are rejected.

## Upgrading

- Replace `plugins/DevTools.phar` and restart the server.
- No manifest or workflow changes are required for supported projects.
- Reduce archives that exceed the documented processing limits before building or extracting them.

The release tag and checksum manifest are signed with the maintainer's GPG key. Verify `SHA256SUMS.txt.asc` before checking the downloaded assets against `SHA256SUMS.txt`.
