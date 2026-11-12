# DevTools ecosystem inventory

This page gives a short human-readable summary of public projects whose default-branch workflows reference DevTools. The complete inventory and its counts are stored in [`ecosystem.json`](ecosystem.json). The data contract is described by [`ecosystem.schema.json`](ecosystem.schema.json).

The latest stable release represented by this inventory is DevTools `v4.0.3`, at commit `106547e8b142a3f6a65a4b3c66d1ace477e56f10`. Use `@v4` for compatible v4 updates or `@latest` to follow stable releases across major versions. Exact release tags and commit pins are also supported. This inventory records the current stable release, not each project's selected pin.

## Inventory

The table below summarizes the repository totals stored in `ecosystem.json`.

| Organization | Repositories |
| --- | ---: |
| [AxolotlBucketLabs](https://github.com/AxolotlBucketLabs) | 7 |
| [NhanAZ-Plugins](https://github.com/NhanAZ-Plugins) | 93 |
| [NhanAZ-Libraries](https://github.com/NhanAZ-Libraries) | 7 |
| [thebigcrafter](https://github.com/thebigcrafter) | 24 |
| [ReinfyTeam](https://github.com/ReinfyTeam) | 12 |
| **Total** | **143** |

## Using the data

Use [`ecosystem.json`](ecosystem.json) when a script or report needs the full repository list or organization totals. Use this page when a person only needs the scope, method, or current summary.

Run `composer ecosystem` after changing the JSON or schema. The check validates the JSON structure used by this repository, repository URLs, organization totals, duplicate entries, and the relationship between the two repository groups.

## Scope and method

A repository is counted when its public default-branch workflow references DevTools or one of DevTools' reusable workflows. Archived repositories, organization-level repositories, and repositories that only mention DevTools in documentation are excluded.

The transferred ScoreHud and await-generator repositories are also excluded because their default-branch workflows do not reference DevTools. BedrockNexus is documented separately because its workflow template is maintained outside this inventory.

## Limitations

This inventory is maintained documentation, not telemetry. It can become stale when a project changes its workflow. Update [`ecosystem.json`](ecosystem.json) when the inventory needs to reflect a later state.

The [ecosystem compatibility check](development.md#ecosystem-compatibility-check) builds every listed repository for each recorded artifact role. It uses a separate build profile so the inventory remains a simple repository list.
