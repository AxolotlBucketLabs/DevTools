# DevTools ecosystem inventory

This page gives a short human-readable summary of public projects using DevTools in a GitHub Actions workflow. The complete inventory and its counts are stored in [`ecosystem.json`](ecosystem.json). The data contract is described by [`ecosystem.schema.json`](ecosystem.schema.json).

## Inventory

DevTools is currently verified in 143 public repositories across five organizations.

The audit inspected 178 non-archived repositories and 208 default-branch workflow files. All counted repositories resolve to `v3.1.0` through the maintained `@v3` channel or the exact release commit `d5e9f9ef1512e8586df94b9cc5835c088e48229d`.

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
