# DevTools dependency policy

DevTools targets Axolotl-PM only. Folder loading, virion selection, dependency preparation, staging, diagnostics and artifact orchestration remain owned by this project. Small utilities use PHP or the existing Axolotl-PM APIs. This does not mean replacing the PHP parser or QA tools with new subsystems.

## Direct dependencies

The accepted Composer runtime requirements are PHP `^8.4`, `ext-json`, `ext-phar`, `ext-yaml`, and `nikic/php-parser:^5.6`. The parser is essential for AST-based shading and its existing license is retained. The current lock selects `nikic/php-parser` `v5.9.0`.

The accepted development requirements are `friendsofphp/php-cs-fixer:^3.64`, `phpstan/phpstan:^2.1`, `phpunit/phpunit:^10.5`, and `axolotl-pm/pocketmine-mp:5.51.1`. Axolotl-PM is pinned by `composer.lock`, rather than resolved afresh for a test run. Packages such as Symfony components or Composer utilities are dependencies of those tools/server packages, not direct runtime dependencies introduced by DevTools.

Axolotl-PM 5.51.1 requires BedrockProtocol 64.0.0 and NetherNet 0.3.2 in the current lock. Installing the development dependencies therefore also requires `ext-webrtc`; use a current Axolotl-PM PHP binary.

The standalone CLI currently uses Axolotl-PM manifest value objects from the development installation. Consequently, its documented source-checkout installation includes development dependencies, even though it never starts a server. On a server, Axolotl-PM itself supplies those classes. The shipped DevTools plugin PHAR does not contain Axolotl-PM, PHPUnit, PHPStan, PHP CS Fixer, or Composer's autoloader.

PHP 8.4 is the source, CLI and build minimum. The complete CI quality gate runs on PHP 8.4 and 8.5. Axolotl-PM runtime smoke evidence identifies its actual PHP version separately; this release's runtime smoke is on PHP 8.4.24 ZTS and does not claim a PHP 8.5 server test.

## Abandoned server dependencies

Reviewed on 2026-11-03. All ten packages below are required by [Axolotl-PM 5.51.1](https://github.com/axolotl-pm/PocketMine-MP/blob/e881fc1bc1c401545baf6bd1f824e41ff3f57bd1/composer.json) and appear in the development section of DevTools' lockfile. Packagist marks each abandoned without a suggested replacement; their source repositories are archived.

| Package | Locked version |
| --- | --- |
| `pocketmine/binaryutils` | `0.2.7` |
| `pocketmine/callback-validator` | `1.0.4` |
| `pocketmine/color` | `0.3.1` |
| `pocketmine/errorhandler` | `0.7.1` |
| `pocketmine/log` | `0.4.0` |
| `pocketmine/math` | `1.0.0` |
| `pocketmine/nbt` | `1.2.0` |
| `pocketmine/raklib` | `1.2.1` |
| `pocketmine/raklib-ipc` | `1.0.1` |
| `pocketmine/snooze` | `0.5.0` |

`composer outdated "pocketmine/*" --locked` reported all ten at their latest stable versions. A targeted update with `--minimal-changes --dry-run` proposed no lockfile changes. Retain these versions until a compatible replacement is verified with Axolotl-PM: removing them would leave required server classes unavailable, including in the source-checkout CLI installation. Review again by 2026-11-20, or when the server pin changes, a replacement is published, or an advisory appears.

`composer audit --locked` reported no vulnerability advisories and ten abandoned-package findings. The full audit remains enabled and returns nonzero for these findings. `composer audit --locked --no-dev` reported no findings, but excludes the dependencies needed by the source-checkout CLI. The PHAR gate below checks the separate shipped-plugin boundary.

## Artifact boundary

The self-build's `devtools.yml` includes only `vendor/nikic/php-parser/lib` and its license. `bin/validate-artifact.php` enforces that allowlist against the actual PHAR, in addition to its existing signature, manifest, secret and development-file checks. Regression tests deliberately add QA, server or Composer bootstrap files to a valid artifact and require the release gate to reject them.

References to upstream implementations are research provenance, not copied libraries renamed under `src/`. See [third-party notices](../THIRD_PARTY_NOTICES.md). Generic shading regressions use fixtures owned by DevTools. Successfully building any particular external package is not the acceptance criterion for the shader.

## User dependencies and CI tooling

Dependencies of a plugin being built belong to that plugin. Keep the plugin's required libraries when configuring a build. Unsupported preparation or shading contracts produce a diagnostic. They do not justify removing the dependency to obtain a green build. See [supported dependency formats](dependencies.md).

GitHub checkout, upload and download actions, the pinned Axolotl-PM PHP setup helper, actionlint, GitHub CLI, Node's standard library and the runner's archive utilities are CI tooling. They are not runtime plugin libraries or startup integrations. Publication and credentials remain in the workflow layer. Dependency preparation and network access are explicit. Server startup and ordinary build do not fetch dependencies.

The direct dependency declarations, copied source, scripts, workflows and final PHAR all need review when this boundary changes. Do not hide a new library by copying it into `src/`, and do not replace a mature subsystem with custom code solely to reduce a package count.
