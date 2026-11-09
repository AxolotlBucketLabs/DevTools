# Installation

## Fresh install

1. Use Axolotl-PM with the API declared by DevTools and its required PHP extensions. See the [verified runtime target](runtime-validation.md).
2. Download the PHAR from the [latest DevTools release](https://github.com/AxolotlBucketLabs/DevTools/releases/latest) and compare its SHA-256 with the release checksum. GitHub's "Source code" archives are not installable plugins.
3. Stop the server and copy the file to `plugins/DevTools.phar`.
4. Start the server. DevTools creates server-root `virions/` and `build/` directories if they are missing.
5. Run `/devtools status` as an operator and check that the displayed version matches the release.

DevTools creates `config.yml` in its data directory on first startup. Server-side `doctor`, `build` and `extract` commands run on a dedicated worker thread and are enabled by default for compatibility. If the production server only needs folder plugin and virion loading, set those three command options to `false`. This does not disable loading or the lightweight inspection commands.

DevTools cannot bootstrap itself from a source folder on an otherwise unmodified server. Always install its release PHAR first.

## Confirm folder loading

Download and extract the release asset `DevTools-examples-VERSION.zip`. Replace `VERSION` with the release version. Copy `HelloShared/` to `plugins/HelloShared/` and `SharedGreeting/` to `virions/SharedGreeting/`, then restart. The server log should report one loaded virion and enable `HelloShared`. Run these commands.

```text
/devtools virions
/devtools doctor HelloShared
```

Remove the example afterward if it is not needed.

## Update

1. Read the release notes and migration guide.
2. Stop the server.
3. Keep the previous DevTools PHAR as a rollback copy outside `plugins/`.
4. Replace `plugins/DevTools.phar` with the new artifact.
5. Start the server and run `/devtools status`, `/devtools virions`, and `/devtools doctor`.

Do not keep two DevTools PHARs in `plugins/`. Axolotl-PM will detect a duplicate plugin name.

## Roll back

Stop the server, restore the previously working `plugins/DevTools.phar`, and restart. DevTools does not maintain a database or change plugin source, so no data conversion is needed.

## Uninstall

1. Build any folder plugin that must continue running in production.
2. Stop the server.
3. Remove `plugins/DevTools.phar`.
4. Remove or archive folder plugins that depended on the DevTools loader. Axolotl-PM cannot load them afterward.
5. Remove `virions/` and `build/` only if their contents are no longer needed. DevTools never deletes these user directories during uninstall.
