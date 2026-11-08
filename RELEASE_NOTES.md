# DevTools 3.2.0

DevTools 3.2.0 adds multilingual human-facing output for server commands and the CLI.

## Highlights

- Include translated catalogs for 14 Axolotl-PM locales: `bul`, `chs`, `deu`, `eng`, `fra`, `ita`, `jpn`, `kor`, `lav`, `nld`, `rus`, `spa`, `tur` and `vie`.
- Follow the server language with `language: auto`, or select a locale explicitly in `config.yml`.
- Select a CLI language with `--lang=CODE` or the `DEVTOOLS_LANG` environment variable.
- Localize lifecycle messages, command summaries, common build and extraction results and CLI help.
- Keep detailed validator and builder diagnostics in English so paths, package names and error details remain exact.

## Compatibility and upgrade notes

- This is an additive, backward-compatible release for the existing v3 workflow channel.
- Existing server configurations continue to use automatic server-language selection unless `language` is changed.
- The CLI JSON schema and build, extraction and dependency behavior are unchanged.
- PHAR creation still requires PHP to run with `phar.readonly=0`. Loading an existing PHAR does not.
