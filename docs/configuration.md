# Configuration

DevTools creates a server-local `config.yml` on first startup. During server startup and normal local build resolution, DevTools does not download source, follow symlinks, overwrite output without permission or hot-reload PHP.

The server configuration controls only the potentially expensive administrative commands. Folder plugin loading, shared virion loading, the `status` command and the `virions` command remain available when these commands are disabled.

```yaml
commands:
  doctor: true
  build: true
  extract: true
```

On a production server that uses DevTools only for folder plugins and shared virions, set the expensive commands to `false`.

```yaml
commands:
  doctor: false
  build: false
  extract: false
```

Server-side `doctor`, `build` and `extract` operations run on a dedicated worker thread and never use PocketMine's central async task pool. DevTools accepts only one background operation at a time. A second operation is rejected while the first is running. The CLI and GitHub Actions remain synchronous because they do not share a server tick thread.

An individual folder plugin may contain an optional `devtools.yml`.

```yaml
virions:
  - name: ExampleVirion
    version: ^1.0.0

include-paths:
  - runtime-data/schema.json
```

## `virions`

Each entry is either a plain name, meaning any valid version, or a map with exactly `name` and `version`. The supported constraints are listed below.

- Use `*` for any valid version.
- Use exact versions such as `1.2.3`.
- Use caret ranges such as `^1.2.3`.
- Use tilde ranges such as `~1.2.3`.
- Use wildcards such as `1.*` or `1.2.*`.
- Use comparison ranges such as `>=1.2.0 <2.0.0`.

Unknown keys, invalid names, empty values, unsupported expressions, duplicate copies of the selected version, and unsatisfied constraints fail with the responsible file and requirement in the message.

## `include-paths`

These are project-relative runtime files or directories to add to a PHAR. They are not needed for ordinary `src/` and `resources/`, which are included automatically. PHP source files with `.php`, `.inc` or `.phtml` extensions inside these paths are shaded and checked for undeclared virion references like the plugin's `src/` files.

Paths must exist inside the project. Absolute paths, parent traversal, symlinks, build-output overlap, hidden or temporary content, and non-portable Windows path names are rejected. Add only files required at runtime. Do not add `vendor/`, tests, local configuration, credentials, or caches.

Paths that differ only by letter casing are rejected instead of silently replacing one another, because they may refer to different files on case-sensitive systems and the same path on case-insensitive systems.

## `import-poggit-libraries`

Set this optional boolean to `false` when a project keeps legacy `.poggit.yml` declarations for Poggit but uses its reviewed Composer lock or explicit `devtools.yml` declarations as the DevTools dependency source. It defaults to `true`. This setting only changes DevTools dependency selection. It never downloads the declared libraries. See [dependency preparation](dependencies.md) for the migration workflow.

Composer `extra.devtools.virions` and local `.poggit.yml` `libs` remain supported for migration. `devtools.yml` is the clearest format for new non-Composer projects.

For the bounded Composer v3 workflow, root `require` and `composer.lock` are the source of dependency selection. Do not repeat those packages in `devtools.yml`. Run the explicit [preparation step](dependencies.md), then point build and doctor at the prepared directory. Runtime server loading uses server-root `virions/`. A virion can itself declare the same `virions` list in `virion.yml` for local transitive requirements.
