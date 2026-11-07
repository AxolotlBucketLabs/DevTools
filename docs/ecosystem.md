# DevTools ecosystem inventory

This page gives a short human-readable summary of public projects using DevTools in a GitHub Actions workflow. The complete inventory and its counts are stored in [`ecosystem.json`](ecosystem.json). The data contract is described by [`ecosystem.schema.json`](ecosystem.schema.json).

## Snapshot

The inventory was checked on 2026-11-07. It contains 79 repositories that currently resolve to the stable DevTools release and 64 repositories migrated from the v2 channel or an earlier v3 checkout during the audit. There are 143 repositories in total.

The audit inspected 178 non-archived repositories and 208 default-branch workflow files across five organizations. All counted repositories resolve to `v3.0.0` through the maintained `@v3` channel or the exact release commit `04fd726101109739bf1fd629dbe8499d3e2864bb`.

| Organization | Current | Migrated | Total |
| --- | ---: | ---: | ---: |
| [AxolotlBucketLabs](https://github.com/AxolotlBucketLabs) | 7 | 0 | 7 |
| [NhanAZ-Plugins](https://github.com/NhanAZ-Plugins) | 41 | 52 | 93 |
| [NhanAZ-Libraries](https://github.com/NhanAZ-Libraries) | 5 | 2 | 7 |
| [thebigcrafter](https://github.com/thebigcrafter) | 18 | 6 | 24 |
| [ReinfyTeam](https://github.com/ReinfyTeam) | 8 | 4 | 12 |
| **Total** | **79** | **64** | **143** |

## Using the data

Use [`ecosystem.json`](ecosystem.json) when a script or report needs the full repository list, migration list, or organization totals. Use this page when a person only needs the scope, method, or current summary.

Run `composer ecosystem` after changing the JSON or schema. The check validates the JSON structure used by this repository, repository URLs, organization totals, duplicate entries, and the relationship between the two repository groups.

## Scope and method

A repository is counted when its public default-branch workflow references DevTools or one of DevTools' reusable workflows. Archived repositories, organization-level repositories, and repositories that only mention DevTools in documentation are excluded.

The transferred ScoreHud and await-generator repositories are also excluded because their default-branch workflows do not currently reference DevTools. BedrockNexus is documented separately because its workflow template is maintained outside this inventory.

The migration group records projects that were already using DevTools but still referenced the v2 channel or a pre-v3 checkout. Their default-branch workflows now use the v3 workflow or the exact `v3.0.0` release commit.

## Limitations

This is a dated documentation snapshot, not telemetry. It can become stale when a project changes its workflow. Run a new audit and update [`ecosystem.json`](ecosystem.json) when the inventory needs to reflect a later state.
