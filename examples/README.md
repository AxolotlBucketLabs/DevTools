# End-to-end example

HelloShared and SharedGreeting are dedicated examples maintained by DevTools. They demonstrate loading source, using a shared library and building a standalone PHAR.

1. Copy `SharedGreeting/` to the server-root `virions/` directory.
2. Copy `HelloShared/` to `plugins/`.
3. Start the server and run `/devtools virions` followed by `/devtools doctor HelloShared`.
4. Run `/devtools build HelloShared` to create `build/HelloShared.phar` with the virion shaded inside it.

`HelloShared/devtools.yml` requires `SharedGreeting ^1.0.0`. During development, DevTools loads one compatible shared copy. During a standalone build, DevTools moves that virion below a private namespace, so the resulting plugin does not require DevTools or the shared virion on a production server.

## Build automatically on every commit

This example also contains `.github/workflows/build.yml`. Extract the complete examples ZIP into an empty GitHub repository and push it. The public action builds `HelloShared.phar` on every push and pull request, then uploads it to that run's **Artifacts** section.

The bundled workflow uses the `NhanAZ/DevTools@v2` action channel. For reusable workflows, use the corresponding `@v2` reference from the main GitHub Actions guide. Replace `@v2` with `@latest` only when the repository intentionally accepts stable major upgrades. Use `@v2.0.1` for a release-pinned workflow or a full commit SHA for strict reproducibility. The called workflow selects its builder from the same reference. The action step performs the build. The following `actions/upload-artifact` step makes the resulting PHAR downloadable. The PHAR is not committed back into the repository. New plugin repositories can use the reusable workflow described in the main guide instead.

The example intentionally omits `phpstan`, so analysis is off. The [GitHub Actions guide](https://github.com/NhanAZ/DevTools/blob/v2/docs/github-actions.md) shows how to opt in with an explicitly pinned server source.

## Coding agent template

The ZIP also includes `agent/AGENTS.md`. Copy it to the root of a plugin repository when AI coding agents should preserve DevTools workflow rules across sessions. For a one-time setup, paste the [versioned AI agent prompt](https://github.com/NhanAZ/DevTools/blob/v2/docs/ai-agent.md) into the agent instead.
