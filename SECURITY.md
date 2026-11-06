# Security policy

## Supported versions

The latest released version receives security fixes.

## Reporting

Do not publish an exploitable archive, path traversal, arbitrary file write, or unsafe shading case before maintainers can respond. Use the repository's private security-advisory feature when available. Otherwise, open a minimal issue that requests a private contact and omits exploit details.

Include the DevTools version, Axolotl-PM version and API, operating system, relevant manifest and configuration, the smallest safe reproduction, expected destination, and observed destination. Never include credentials or private server data.

## Security boundaries

Plugin and virion source is trusted development code when loaded by PHP. Metadata inspection, building, and extraction do not execute it. Builds use local inputs. Staging and extraction reject symbolic links, non-portable paths, and destination escape. Shading rejects ambiguous namespaces. Existing build outputs require explicit overwrite. Extraction stages a sibling tree and installs it with backup and restore.
