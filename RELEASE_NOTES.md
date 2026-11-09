# DevTools 3.3.1

DevTools 3.3.1 completes the localization and documentation follow-up for the v3 workflow while aligning the source tree with PocketMine-MP formatting conventions.

## Highlights

- Complete the runtime message catalogs for all 14 supported Axolotl-PM locales.
- Localize the remaining user-facing CLI and runtime diagnostics while keeping machine-readable JSON output stable.
- Clarify runtime startup phases and align the CLI, ecosystem and GitHub Actions documentation with the current v3 interfaces.
- Use PocketMine-MP-compatible PHP formatting rules for the source, tests, tools and examples.
- Add a dedicated GitHub Actions check for PHP code style.

## Compatibility and upgrade notes

- This is a patch release for the existing v3 workflow channel.
- Existing plugin and virion projects do not need a migration.
- The CLI JSON schema and PHAR build or extraction formats are unchanged.
