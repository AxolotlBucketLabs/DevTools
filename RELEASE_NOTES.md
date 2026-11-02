# DevTools 2.0.1

This patch release keeps the v2 workflow contract and makes the composite action usable with PHP environments that do not provide the optional `ext-webrtc` extension.

## Fixes

- The composite action now ignores only `ext-webrtc` when installing its build-time Composer graph and when preparing project dependencies. This extension is not needed by DevTools' build or shading operations.
- PHPStan server dependencies continue to use the existing `--ignore-platform-reqs` behavior.
