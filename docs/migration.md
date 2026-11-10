# Migrate from other tools

DevTools supports Axolotl-PM. The table below maps common workflows to the supported interface.

| Tool and workflow | DevTools equivalent | Limits |
| --- | --- | --- |
| pmmp/DevTools folder loading | Install DevTools PHAR and load folders containing `plugin.yml` and `src/` | No hot reload, plugin scaffolding, permission inspection, event inspection or `makeplugin all` |
| pmmp/DevTools packaging and extraction | `/devtools build`, `/devtools extract`, CLI `build` and `extract` | No arbitrary multiple source roots. Extraction remains transactional and archive-safe. |
| DEVirion shared development libraries | Local folder and PHAR virions, compatible transitive selection and shared class loading | No downloads or source namespace isolation. Incompatible shared requirements fail |
| pmmp-plugin-actions builds | Composite or reusable workflow using the same CLI, with optional PHPStan and preparation | PHP setup uses a pinned Axolotl-PM helper |
| pmmp-plugin-actions releases and nightlies | Publish checked artifact bytes with exact tag matching and prerelease handling | Nightlies use immutable commit tags rather than a moving release URL |
| Pharynx Composer Virion v3 builds | Prepare supported installed, locked virions and build with private shading | Restricted autoload, namespace, resource and platform layouts. No general Composer bundling or shared namespace contract |

Read [dependency support](dependencies.md), [CLI usage](cli.md) and [GitHub workflows](github-actions.md) before changing a project. Successful source loading does not prove that a built PHAR is standalone.

## Dependency declarations

Keep valid local packages and `devtools.yml` declarations. A virion can declare transitive requirements in its own `virion.yml`. Local workflows need no additional lockfile.

Legacy `.poggit.yml` project `libs` and Composer `extra.devtools.virions` remain accepted metadata. They do not instruct DevTools to download anything. Prepare matching packages locally from their authoritative sources.

Remove development-tool entries for DevTools or DEVirion from `depend`, `softdepend` and `loadbefore` in a distribution plugin's `plugin.yml`. Intentional dependencies on other plugins remain external Axolotl-PM dependencies.

## Commands and paths

`/makeplugin <plugin>` and `/extractplugin <phar>` route to build and extract. They accept the documented optional `--overwrite`, not every option of the original tools. Unknown, duplicate or trailing arguments fail before writing output.

`/devtools doctor` checks project structure, declarations and project-local virion selection. If source folders share a manifest name, select an explicit folder path under `plugins/` rather than relying on discovery order.

`bin/devtools-build.php` preserves its original defaults and resolves explicit relative paths from the working directory. `bin/devtools.php` resolves them from the selected project. Both use the same builder. Use `--json` for automation.

## Library boundaries

Shared source plugins need one compatible library version. Each built PHAR has private library types and static state. Keep privately bundled objects out of cross-plugin APIs. Define those APIs in an intentionally external plugin dependency instead.

Unsupported Composer package layouts and dynamic class references fail explicitly. Adapt the package to the documented subset or retain a compatible packaging tool for that project.

## Direct PHP integrations

Direct PHP integrations must update the constructors and dual-build calls below. CLI commands, server commands and GitHub workflow inputs keep their existing syntax.

`DualArtifactBuilder` now requires `PluginManifestReader`, `VirionManifestReader` and `ProjectRoleDetector` after its existing constructor dependencies. Call `prepare()` with the project root, then pass the returned `DualArtifactProject` to `build()` instead of a root string and two output names.

```php
$project = $dualArtifactBuilder->prepare($projectRoot);
$artifacts = $dualArtifactBuilder->build($project, $virionsDirectory, $outputDirectory, $overwrite);
```

The builder derives `<plugin-name>-plugin.phar` and `<virion-name>-virion.phar` from the manifests. For custom pair names, build both artifacts into a private staging directory with `PharBuilder` and `VirionBuilder`, then install them together with `ArtifactSetInstaller::install()`.

For `Cli\Application` construction, replace its `PluginManifestReader` and `PluginProjectValidator` arguments with a `ProjectDoctor`. Remove the `ProjectRoleDetector` argument and supply a `DualArtifactBuilder` as the required argument after `VirionManifestReader`. The optional fallback builder has been removed.

The CLI uses `build.both_required` for dual-role rejection, matching the server worker. Custom translation catalogs can remove the unused `cli.both_roles_required` entry.

`FolderPluginLoader` now accepts one `PluginDependencyIndex` in place of its configuration and dependency failure callbacks. Collect the index through `Runtime\PluginDependencyCollector` before shared virion loading, then pass that same index to the loader after the registry is populated. The optional debug argument follows the index.

`BuildWorkerApplication::run()` retains its serialized request and result contract. Its dependency lookup array has been removed internally. Standalone builder constructors accept optional installer and provenance dependencies with working defaults.

Installation failures now use the common installer's diagnostics. Overwrite protection, rejection of unsafe destinations and restoration of previous artifacts retain their existing behavior. Direct PHP integrations should catch `BuildException` rather than match the former builder-specific replacement messages.

## Publication

A tag such as `v1.2.3-rc.1` must match `plugin.yml` version `1.2.3-rc.1` exactly. Nightlies use `nightly-<full commit SHA>` tags. The reusable publication workflow does not replace existing releases or move published tags. See [GitHub Actions](github-actions.md#tag-release) for configuration.
