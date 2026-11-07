# Troubleshooting

Choose the entry that matches the reported problem.

- If a folder is not loaded, check the released DevTools PHAR, folder location, required virions, and `/devtools doctor`.
- If `plugin.yml` or the main source is missing, check that the project contains both `plugin.yml` and `src/`, then check `main` and `src-namespace-prefix`.
- If a virion is skipped or conflicts, run `/devtools virions` and check the declarations and local versions.
- If a build fails, read the reported path and suggested next step before changing source or using `--overwrite`.
- If extraction is refused, keep the safety check enabled and obtain a clean PHAR instead of weakening it.

## The server ignores my plugin folder

Confirm the released DevTools PHAR is already in `plugins/` and loaded at startup. DevTools cannot bootstrap itself from a folder. The project must be an immediate child of `plugins/` and contain both `plugin.yml` and `src/`. Hidden directories, build output, caches and symlinks are skipped.

Run `/devtools doctor FolderOrPluginName` and read the reported file, value and suggested next step.

## `plugin.yml is missing` or `main source ... is missing`

Create `plugin.yml` at the project root. Check `main` and `src-namespace-prefix` against the layouts in [folder plugins](plugin-directories.md). Match letter case exactly, even on Windows.

## Axolotl-PM reports an API, dependency, cycle, or duplicate error

DevTools does not bypass Axolotl-PM's safety checks. Update the plugin's `api`, install required hard dependencies, remove the dependency cycle or duplicate copy, or correct load declarations. Missing `softdepend` entries do not block loading, but code must still handle their absence.

## A folder plugin requires a missing virion

DevTools rejects the affected folder plugin before Axolotl-PM enables it. Prepare the locked Composer packages into a new directory, copy the resulting virion package into the server's `virions/` directory, and restart the server. For a packaged plugin, build with dependency preparation enabled so the virion is shaded into the PHAR. Do not rely on the plugin's private `vendor/` directory for shared folder loading.

## A virion is skipped

The message states whether it is incompatible with PHP or the server API, outside a declared version range, or lower than the selected compatible version. Run `/devtools virions`, then update `devtools.yml` or remove obsolete copies.

## A virion has a conflict

Remove duplicate selected versions, align all plugin constraints so one version satisfies them, or change overlapping antigen or class namespaces. DevTools intentionally loads no ambiguous copy.

## `phar.readonly is enabled`

Start the server or PHP CLI with `phar.readonly=0` only for the trusted build process. Loading an existing PHAR does not require this setting.

## Build says a development-only namespace remains

Declare the referenced local virion in `devtools.yml`, or remove the runtime dependency. DevTools will not produce a PHAR that works only while shared development code happens to be present.

## Build refuses to overwrite output

Inspect the existing artifact first. If replacement is intended, repeat with `--overwrite`. Source and the previous output remain protected on failed builds.

## Extraction is refused

DevTools rejects traversal, absolute or drive paths, alternate data streams, reserved Windows names, null bytes, symlinks, duplicate or case-conflicting entries, prefix conflicts, unsafe destinations, and unapproved overwrites. Obtain a clean PHAR from a trusted source. Do not weaken these checks.

## Reporting a bug

Follow [SUPPORT.md](../SUPPORT.md) and the bug form. Include versions, OS, sanitized configuration and logs, exact steps, expected behavior, and actual behavior. Never post tokens or private server data.
