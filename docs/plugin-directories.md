# Folder plugins

Folder loading is intended for development, where editing a source file and restarting the server is more convenient than rebuilding a PHAR after every change.

## Supported layouts

With `src-namespace-prefix: Example\Plugin`, main class `Example\Plugin\Main` belongs at `src/Main.php`.

| Path | Contents |
| --- | --- |
| `plugins/MyPlugin/plugin.yml` | Plugin manifest |
| `plugins/MyPlugin/src/Main.php` | Main class |
| `plugins/MyPlugin/resources/` | Optional resources |

Without `src-namespace-prefix`, the same class belongs at `src/Example/Plugin/Main.php`.

The folder name does not determine the plugin name. `plugin.yml` does. Namespace, directory, and filename casing must match exactly so a project tested on Windows also works on Linux.

## Validation and server behavior

DevTools validates the manifest, main namespace, path and declaration, API syntax, dependencies, resources, casing, and symbolic links before registration. Axolotl-PM's plugin manager then remains authoritative for duplicate plugin names or classes, API compatibility, missing hard dependencies, soft dependencies, dependency cycles, `loadbefore`, startup order, disable state, and failures during loading or enabling.

If a folder and a PHAR declare the same plugin name, DevTools logs a warning. The current Axolotl-PM loader API registers custom loaders after the built-in PHAR loader, so DevTools cannot guarantee folder precedence for that plugin duplicate. Remove the PHAR or verify the startup log. This limitation does not apply to virions, whose resolver explicitly prefers folders over same-name PHARs.

When DevTools is installed as a PHAR and its source checkout is kept at `plugins/DevTools/`, that checkout is reserved for development commands and is skipped by the folder loader. Other immediate child folders are still loaded normally.

Run `/devtools doctor [plugin]` for project-local diagnostics. Server-level dependency and enable errors remain in the Axolotl-PM log because they depend on every installed plugin.

Paths containing spaces or Unicode are supported through filesystem APIs and are covered by validation and build tests. Project roots, manifests, source roots, resources, and main-class path components may not be symlinks. Copy trusted development source into the server tree instead.

## Development versus production

Folder plugins are mutable and cannot provide a reproducible deployment. Before deploying to production, complete these steps.

1. Run `/devtools doctor MyPlugin`.
2. Run `/devtools build MyPlugin`.
3. Test `build/MyPlugin.phar` on a clean server without DevTools or shared virions.
4. Deploy that PHAR, not the source folder.
