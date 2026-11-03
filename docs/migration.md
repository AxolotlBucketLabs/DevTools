# Migrate from other tools

DevTools supports Axolotl-PM. The table below maps common workflows to the supported interface.

| Tool and workflow | DevTools equivalent | Limits |
| --- | --- | --- |
| pmmp/DevTools folder loading | Install DevTools PHAR and load folders containing `plugin.yml` and `src/` | No hot reload, plugin scaffolding, permission inspection, event inspection or `makeplugin all` |
| pmmp/DevTools packaging and extraction | `/devtools build`, `/devtools extract`, CLI `build` and `extract` | No arbitrary multiple source roots; extraction remains transactional and archive-safe |
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

## Publication

A tag such as `v1.2.3-rc.1` must match `plugin.yml` version `1.2.3-rc.1` exactly. Nightlies use `nightly-<full commit SHA>` tags. The reusable publication workflow does not replace existing releases or move published tags. See [GitHub Actions](github-actions.md#tag-release) for configuration.
