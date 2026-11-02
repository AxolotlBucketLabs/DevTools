# DevTools 2.0.0

DevTools loads folder plugins and shared development virions on Axolotl-PM, builds standalone plugin and virion PHARs, and provides matching CLI, server-command and GitHub Actions workflows.

## Breaking workflow changes

- Remove the obsolete `devtools-ref` input from reusable build and release workflows. The builder always follows the immutable revision of the called workflow.
- Remove the `retention-days` input and upload override. GitHub repository, organization or enterprise policy now controls Actions artifact and log retention.
- Consumer workflow templates no longer cancel an earlier build when a newer commit reaches the same ref, so each push or pull request remains eligible to produce an artifact.

## Migration from v1

Update the reusable workflow reference to the reviewed v2 commit and remove these inputs from the caller:

```diff
 with:
-  devtools-ref: <old-builder-sha>
-  retention-days: 14
   php-version: '8.4'
```

The `devtools-ref` value was already ignored by v1. The v2 workflow derives the builder identity from GitHub's reusable-workflow context. Artifact retention is configured in the caller repository's Actions settings.

## Existing behavior retained

- Plugin, virion and dual-role builds remain separate validated artifacts.
- `overwrite` remains opt-in.
- Composer dependency preparation and PHPStan remain opt-in.
- PHAR inspection, SHA-256 handoff, path validation and release verification remain required.
