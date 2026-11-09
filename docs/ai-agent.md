# Build with a coding agent

The automation interface is the [CLI contract](cli.md). Commands, path rules, diagnostic codes, JSON schema and exit statuses are maintained there. Dependency limitations live in [dependency support](dependencies.md), and CI and publication inputs in the [GitHub Actions guide](github-actions.md). Use the documentation from the exact DevTools revision selected for the task.

## Task template

```text
Build this Axolotl-PM plugin with AxolotlBucketLabs/DevTools.
1. Read the project's instructions, plugin.yml, devtools.yml, Composer lockfile and metadata and existing workflows. Preserve unrelated changes.
2. Read docs/cli.md, docs/dependencies.md and docs/github-actions.md from the exact DevTools checkout in use. Run --help --json and --version --json.
3. Run `doctor --project=PROJECT_PATH --json`. Distinguish malformed project, missing prepared dependencies and unavailable environment. Replace `PROJECT_PATH` with the absolute project path.
4. Use local declared virions when available. Never guess a repository from a package name. For supported Composer virions, use the reviewed composer.lock, install --no-dev --no-scripts --no-plugins, then explicitly prepare a new directory. Do not update versions without authorization.
5. Build using the same CLI with --json and phar.readonly=0. Do not enable overwrite unless replacing that output is intended.
6. Consume data.artifact and compare the file's SHA-256 with data.sha256. Run inspect. Do not infer the artifact filename.
7. Keep project-required checks. PHPStan is otherwise opt-in. Report structure, dependency and packaging checks separately from static analysis and a real clean Axolotl-PM runtime test.
8. For GitHub automation, use the workflow and inputs documented in the exact revision. Pin the reusable workflow to a tested commit. It checks out its own builder from that commit. Upload the generated artifact and metadata.
9. Configure release or nightly publication only when requested. Publication uses the checked bytes from the build job, not a second build. Do not actually publish, push or merge without authorization.
10. Report exact artifact path and hash, tool version and revision, selected dependency provenance, checks run, failures and skipped checks and runtime checks not performed. A successful build is not evidence of server boot.
```

Simple plugins need only `plugin.yml` and `src/`. Do not add Composer, a lockfile, agent configuration or `devtools.yml` to a project that does not need them.

A persistent [AGENTS.md template](../examples/agent/AGENTS.md) provides the same direction. If a dependency shape is unsupported, report the exact failing contract and practical alternatives. Do not suppress validation, copy all of `vendor/`, introduce another solver, or silently retain a development-runtime dependency in a distribution PHAR.
