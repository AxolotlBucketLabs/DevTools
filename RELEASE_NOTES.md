# DevTools 4.1.0

DevTools 4.1.0 adds optional SLSA provenance for published PHARs and improves BedrockNexus publishing support.

## Added

- Generate a GitHub artifact attestation for the exact published PHAR when `attest: true` is enabled.
- Add a BedrockNexus publishing workflow template and a tool to synchronize ecosystem release metadata.

## Compatibility

- Attestation is disabled by default. Existing workflow callers do not need permission or configuration changes.
- Existing commands, manifests and public runtime APIs are unchanged.
- PHP 8.4 remains the minimum supported version. CI covers PHP 8.4 and 8.5.

## Upgrading

- No changes are required. To enable attestations, follow the permissions guidance in the GitHub Actions documentation.

The release tag and checksum manifest are signed with the maintainer's GPG key. Verify `SHA256SUMS.txt.asc` before checking the downloaded assets against `SHA256SUMS.txt`.
