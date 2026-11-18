# DevTools ecosystem inventory

This page gives a short human-readable summary of public projects whose default-branch workflows reference DevTools. The complete inventory and its counts are stored in [`ecosystem.json`](ecosystem.json). The data contract is described by [`ecosystem.schema.json`](ecosystem.schema.json).

The latest stable release version and source commit represented by this inventory are stored in the `devtools` object in [`ecosystem.json`](ecosystem.json). The inventory records the current stable release, not each project's selected pin.

## Inventory

The table below summarizes the repository totals stored in `ecosystem.json`.

| Organization | Repositories |
| --- | ---: |
| [AxolotlBucketLabs](https://github.com/AxolotlBucketLabs) | 9 |
| [NhanAZ-Plugins](https://github.com/NhanAZ-Plugins) | 91 |
| [NhanAZ-Libraries](https://github.com/NhanAZ-Libraries) | 6 |
| [thebigcrafter](https://github.com/thebigcrafter) | 24 |
| [ReinfyTeam](https://github.com/ReinfyTeam) | 12 |
| **Total** | **142** |

## Using the data

Use [`ecosystem.json`](ecosystem.json) when a script or report needs the full repository list or organization totals. Use this page when a person only needs the scope, method, or current summary.

Run `composer ecosystem` after changing the JSON or schema. After a stable release is published and its tag is available locally, run `composer ecosystem:sync-release` to update the stable version and commit from the latest stable Git tag. The check validates the inventory structure, release metadata, repository URLs, organization totals, duplicate entries, and the relationship between the two repository groups.

## Scope and method

A repository is counted when its public default-branch workflow references DevTools or one of DevTools' reusable workflows. Archived repositories, organization-level repositories, and repositories that only mention DevTools in documentation are excluded.

The transferred ScoreHud and await-generator repositories are also excluded because their default-branch workflows do not reference DevTools. BedrockNexus is documented separately because its workflow template is maintained outside this inventory.

## Limitations

This inventory is maintained documentation, not telemetry. It can become stale when a project changes its workflow. Update [`ecosystem.json`](ecosystem.json) when the inventory needs to reflect a later state.

The [ecosystem compatibility check](development.md#ecosystem-compatibility-check) builds every listed repository for each recorded artifact role. It uses a separate build profile so the inventory remains a simple repository list.
