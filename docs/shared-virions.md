# Shared development virions

A shared virion is trusted local development code registered once on Axolotl-PM's shared `ThreadSafeClassLoader`. Multiple folder plugins can use its classes without embedding duplicate copies during development. DevTools never downloads virions.

## Package layout

| Path under `virions/ExampleVirion/` | Contents |
| --- | --- |
| `virion.yml` | Library manifest |
| `src/Example/Virion/ClassName.php` | Class below the antigen namespace |
| `src/<Antigen>/...` | PHP source and resources colocated with that source |
| `LICENSE` | Library license |

Root-level `resources/` is not supported for virions. Resources must be colocated under the virion source directory so their paths remain stable after shading. Put a file such as `src/Example/Virion/resources/default.txt` beside the class that uses it and resolve it relative to that class with `__DIR__`.

Source folders are preferred over local `.phar` virions with the same manifest name. The PHAR is not selected as a fallback and is recorded as skipped with a warning, because a development folder is the authoritative local source. Symlinks are rejected.

```yaml
name: ExampleVirion
version: 1.2.3
antigen: Example\Virion
api: 5.0.0
# php: 8.4               optional PHP compatibility
```

`name`, semantic `version`, `antigen`, and at least one of `api` or `php` are required. Every declared class, namespace-level function and namespace-level constant must remain below the antigen and match its path and letter case where applicable.

## Plugin declaration and selection

Declare requirements in the plugin's `devtools.yml`.

```yaml
virions:
  - name: ExampleVirion
    version: ">=1.2.0 <2.0.0"
```

At startup, DevTools gathers folder-plugin requirements, prefers a source folder over same-name PHAR candidates, filters the preferred packages incompatible with the current PHP and server API, then loads the highest semantic version satisfying all declarations. Lower or incompatible versions are recorded as skipped. If no preferred version satisfies every plugin, no packaged fallback is loaded. Two source folders of the selected version are ambiguous and rejected instead of choosing by path order.

The same resolver is used during standalone builds. A missing package or version mismatch fails the build before an artifact is written.

## Debugging

Run `/devtools virions`. Every discovered package is shown as loaded, skipped, failed or conflicting, with its version, location and reason. `/devtools status` reports the loaded count and async-worker class-loader state.

Registration is visible to existing and future async workers because Axolotl-PM shares the loader object. This exposes code only. Plugin objects and mutable main-thread state are not transferred to workers.

## Namespace and class conflicts

DevTools rejects overlapping antigens, duplicate classes, declarations outside an antigen, non-autoloadable casing, duplicate selected versions, invalid packages, and incompatible versions before registration. PHP cannot unload a registered class, so change virion code or version and restart the process rather than attempting hot reload.

See the tested [end-to-end example](../examples/README.md).

## Shared source versus private artifacts

Shared source plugins see the same library classes and main-thread static state. Each production PHAR receives its own shaded library classes. An object from plugin A's library is not an instance of plugin B's shaded type, and their static fields are independent even if their original library version matches. Keep bundled-library objects out of cross-plugin public APIs. Use an explicit plugin-level contract with plain values or deliberately external API types. Bundling public or shared namespaces is not supported.

Async registration makes code available, not mutable main-thread static state. The [real Axolotl-PM smoke harness](runtime-validation.md) checks both shared layouts, object and type identity, static state, two private library versions, and worker calls. It is distinct from unit fixtures and does not prove every third-party library is thread-safe.

Virions can declare a `virions` list in their own `virion.yml` with the same name and version syntax. Both build and shared loading resolve these transitive requirements. This bounded resolver selects the highest compatible candidate and rejects conflicts and nonconvergent graphs. It is not a general backtracking package solver. See [dependency support](dependencies.md).
