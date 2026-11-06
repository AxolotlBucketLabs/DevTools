# Development

Read `PROJECT_MAP.md` and `AGENTS.md` before changing code. Keep work inside folder loading, shared development virions, standalone builds and extraction, or the quality and release support for those workflows.

## Setup and checks

```text
composer install
composer syntax
composer yamllint
composer lint
composer phpstan
composer test
composer build
composer artifact
composer check
```

Focused suites are `composer test:loader`, `composer test:virion`, and `composer test:build`. `composer analyse`, `composer qa`, and `composer build:self` remain compatibility aliases.

Install `yamllint` 1.38.0 in the development environment before running `composer yamllint`:

```text
python -m pip install yamllint==1.38.0
composer yamllint
```

`yamllint` checks tracked project YAML for syntax, duplicate keys, indentation and other structural problems. Test fixtures under `tests/Fixtures/` are excluded because several deliberately contain malformed YAML for failure-path tests. GitHub Actions workflows are checked separately by `actionlint` in CI.

For the CLI use `php -d phar.readonly=0 vendor/bin/phpunit --testsuite cli`. Action and release handoff behavior uses `node --test .github/scripts/*.test.mjs`. The pinned real-server harness is documented under [runtime validation](runtime-validation.md). It runs separately from ordinary builds.

## Shared code graph

DevTools keeps a committed [Graphify](https://github.com/Graphify-Labs/graphify)
code graph at `graphify-out/graph.json`. It is a navigation aid for understanding
relationships across the loader, virion and build modules; it does not replace
tests, PHPStan, or the release checks.

Install Graphify with `graphifyy`, then rebuild the graph from the repository
root with:

```text
graphify extract . --code-only
```

Only `graphify-out/graph.json` is shared. HTML visualizations, reports, caches,
and other generated files remain ignored. After code changes, run
`graphify update .` and include the refreshed graph with the change.
For graph merge conflicts, configure Graphify's merge driver as described in
the [team workflow guide](https://docs.graphify.com/cookbooks/shared-team-graph).

`composer check` is the release-equivalent local gate. It runs strict Composer validation, all-project PHP syntax, style, PHPStan level max, all tests, self-build, and PHAR content, signature and secret validation. It returns nonzero on any failed stage.

## Test strategy

- Loader and validation tests cover discovery, manifests, namespace, letter case, paths and actionable diagnostics.
- Virion tests cover source and PHAR discovery, single registration, async-visible loader use, semantic constraints, selection, missing or conflicting packages, compatibility and duplicate classes.
- Build tests inspect real PHAR contents, resources and licenses, AST rewrites, standalone guards, output protection, and safe extraction.
- `examples/` is an integration fixture as well as user documentation.
- A release candidate should receive a live compatible-server smoke test with the built DevTools PHAR, folder example, shared virion, and produced standalone plugin when practical.

## Release process

The candidate and publication steps are separate. A tag push does not build or publish anything. Existing published releases are never overwritten.

1. Choose the final version before preparing a publication candidate. Synchronize `plugin.yml`, `composer.json` (`extra.devtools.release-version`), `CHANGELOG.md`, and `RELEASE_NOTES.md`. The current source version is not automatically bumped by these workflows.
2. Run `composer check`, `composer audit`, and `node --test .github/scripts/*.test.mjs`. Review any audit findings under the [dependency policy](dependency-policy.md). Commit the intended source and push it to the selected dispatch branch or tag. Wait for its CI checks.
3. Explicitly dispatch **Build DevTools release candidate** (`release-candidate.yml`) on the intended source branch or tag and set `candidate-commit` to that exact full commit SHA. GitHub's dispatch ref selects a branch or tag. The workflow fails if its `GITHUB_SHA` differs from the explicit commit, then checks out that commit directly. It does not choose or update a source branch for you.
4. The candidate workflow verifies version synchronization, runs the complete quality gate, builds and validates the PHAR once, packages the examples and exact release notes, and writes `candidate.json` plus `SHA256SUMS.txt`. It uploads one immutable artifact with commit, run and attempt in its name. Record the run ID, attempt, artifact ID and archive digest from the run summary. Download and inspect that candidate and perform the clean Axolotl-PM runtime checks required for release approval. Candidate metadata records runtime as `not_run`. It does not substitute for those checks.
5. After reviewing the candidate, create and push the version tag pointing to that **same candidate commit**, only when publication has been explicitly authorized. This is a separate maintainer action. Neither workflow creates or moves the version tag. Move `latest` to that same verified commit for every stable release, including a major release. For a compatible v3.x release, move the `v3` major channel to that same verified commit only after the version tag and release assets are validated. A candidate must retain the version recorded in its source. A version change requires a new candidate.
6. Explicitly dispatch **Promote verified DevTools release candidate** (`release.yml`) from the repository default branch, supplying `candidate-run-id`, `artifact-id`, and the existing `tag`. Promotion resolves lightweight and annotated tags, requires that the tag commit equals the successful manual candidate run's head commit, verifies repository, workflow, run, attempt and artifact identity and expiry, and compares the archive digest with GitHub's immutable artifact digest. It then checks every candidate asset hash and exact version tag.
7. Promotion publishes the already verified PHAR and examples ZIP, candidate provenance and checksums, using the candidate's exact release notes. No Composer installation, build, recompression, version rewrite or plugin or runtime code execution occurs in that publication step. Download the published assets and verify them against the reviewed candidate.

The candidate has read-only repository permissions. Promotion alone receives `contents: write`. A failed, incomplete, expired, wrong-commit, wrong-run, wrong-workflow, or modified candidate fails closed. Rerunning the candidate workflow increments its attempt. Review and select the new attempt's artifact rather than assuming an older artifact is still the selected candidate. GitHub artifact retention follows the repository or organization policy. Expired artifacts cannot be promoted.

Local contract tests cover source identity, annotated tag resolution, wrong run, artifact and version, failed checks, archive digest and asset tampering. Local tests and workflow linting do not count as a hosted candidate run, tag push, or real publication. These external actions require explicit authorization and are not part of ordinary development validation.
