# End-to-end example

HelloShared and SharedGreeting are dedicated examples maintained by DevTools. They demonstrate loading source, using a shared library and building a standalone PHAR.

1. Copy `SharedGreeting/` to the server-root `virions/` directory.
2. Copy `HelloShared/` to `plugins/`.
3. Start the server and run `/devtools virions` followed by `/devtools doctor HelloShared`.
4. Run `/devtools build HelloShared` to create `build/HelloShared.phar` with the virion shaded inside it.

`HelloShared/devtools.yml` requires `SharedGreeting ^1.0.0`. During development, DevTools loads one compatible shared copy. During a standalone build, DevTools moves that virion below a private namespace, so the resulting plugin does not require DevTools or the shared virion on a production server.

## Build automatically on every commit

This example also contains `.github/workflows/build.yml`. Extract the complete examples ZIP into an empty GitHub repository and push it. The public action builds `HelloShared.phar` on every push and pull request, then uploads it to that run's **Artifacts** section.

The bundled workflow uses the `NhanAZ/DevTools@v2` action channel. For reusable workflows, use the corresponding `@v2` reference from the main GitHub Actions guide. Replace `@v2` with `@latest` only when the repository intentionally accepts stable major upgrades. Use `@v2.1.0` for a release-pinned workflow or a full commit SHA for strict reproducibility. The called workflow selects its builder from the same reference. The action step performs the build. The following `actions/upload-artifact` step makes the resulting PHAR downloadable. The PHAR is not committed back into the repository. New plugin repositories can use the reusable workflow described in the main guide instead.

The example intentionally omits `phpstan`, so analysis is off. The [GitHub Actions guide](https://github.com/NhanAZ/DevTools/blob/v2/docs/github-actions.md) shows how to opt in with an explicitly pinned server source.

## Composer-prepared virion example

`ComposerPlugin/` demonstrates the Composer path for a plugin that declares a supported Virion v3 package in `composer.json`. Its committed `composer.lock` and local path repository make the example deterministic and independent of Packagist. The CI workflow installs that lockfile with scripts, plugins and the generated Composer autoloader disabled, prepares the package into `.devtools-virions`, then builds the plugin through the same composite action used by external repositories.

For a real project, publish or reference the reviewed Composer package through the repository's normal Composer configuration, commit the resulting `composer.lock`, and enable the action input:

```yaml
with:
  prepare-dependencies: true
  virions: .devtools-virions
```

DevTools still does not download dependencies during server startup or an ordinary local build. The explicit preparation step is the only network-capable part of this workflow.

## Coding agent template

The ZIP also includes `agent/AGENTS.md`. Copy it to the root of a plugin repository when AI coding agents should preserve DevTools workflow rules across sessions. For a one-time setup, paste the [versioned AI agent prompt](https://github.com/NhanAZ/DevTools/blob/v2/docs/ai-agent.md) into the agent instead.
