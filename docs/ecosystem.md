# DevTools ecosystem inventory

This page gives a short human-readable summary of public projects using DevTools in a GitHub Actions workflow. The complete inventory and its counts are stored in [`ecosystem.json`](ecosystem.json). The data contract is described by [`ecosystem.schema.json`](ecosystem.schema.json).

## Inventory

The inventory records 143 public repositories across five organizations, based on the latest audit on 2026-11-08.

The audit inspected 178 non-archived repositories and 208 default-branch workflow files. At audit time, counted repositories used the maintained `@v3` channel or the exact release commit `d3e81dcb5bc0c0d14378279beb689c7ee56ced45`. The `v3` and `latest` channels now point to `v3.3.0` at commit `0fd73cc2c82d03d0d47f6c7ce119859deda042e3`; full-SHA references remain pinned to their selected commit. The repository count has not been re-audited as part of this release.

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

The transferred ScoreHud and await-generator repositories are also excluded because their default-branch workflows do not currently reference DevTools. BedrockNexus is documented separately because its workflow template is maintained outside this inventory.

## Limitations

This inventory is maintained documentation, not telemetry. It can become stale when a project changes its workflow. Update [`ecosystem.json`](ecosystem.json) when the inventory needs to reflect a later state.
