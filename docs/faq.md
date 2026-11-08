# Frequently asked questions

## Is DevTools required on production?

No. A plugin built with declared virions is standalone with respect to those virions. External plugin dependencies still need to be installed on the server. Test the result on a clean server, then deploy only that PHAR.

## Can DevTools load itself from a folder?

Not on a normal server. Axolotl-PM needs a loadable DevTools PHAR before the folder loader exists.

## Does it download virions or Composer packages?

DevTools does not download virion source or a plugin's Composer dependencies during server startup or normal build resolution. Matching virions must already exist in the local `virions/` directory. The GitHub Action installs its own locked builder dependencies on the runner. When PHPStan is enabled, it also installs the checked-out server's locked production dependencies. Those are workflow setup steps, not automatic runtime dependency resolution.

The CLI `prepare` imports supported, already-installed locked Composer virions into a new directory. The action's explicit `prepare-dependencies: true` additionally runs Composer with scripts and plugins disabled. See [dependency support](dependencies.md). This is not support for arbitrary Composer packages or implicit updates.

## Can I keep several versions of one virion?

Yes for local availability, provided one unique highest version satisfies all folder-plugin declarations. DevTools loads only that copy and reports the others. Duplicate copies of the selected version or mutually incompatible requirements are rejected.

## Does shared mean mutable state is shared with async workers?

No. The class-loader registration is shared. Axolotl-PM thread-safety rules still apply to objects and state.

## Does editing a folder plugin hot-reload it?

No. PHP classes cannot be unloaded safely. Restart the process after source changes.

## Why are symlinks rejected?

They can escape trusted roots, change during staging, or behave differently on Windows and Linux. Real directories make loading and artifacts predictable.

## Are paths with spaces or Unicode supported?

Yes. DevTools uses filesystem APIs rather than shell-built commands. Portable archive names and exact case are still required.

## Which languages does DevTools support?

DevTools follows Axolotl-PM's selected language by default and supports `bul`, `chs`, `deu`, `eng`, `fra`, `ita`, `jpn`, `kor`, `lav`, `nld`, `rus`, `spa`, `tur` and `vie`. Set `language` in the server `config.yml` to override `auto`, or use `--lang=CODE` with the CLI. Technical diagnostics fall back to English when an exact translation is not available.
