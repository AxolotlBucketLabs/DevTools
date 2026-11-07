# DevTools 3.1.0

DevTools 3.1.0 adds opt-in DEBUG diagnostics for development loading and build workflows.

## Highlights

- Enable server diagnostics with `logging.debug: true` in DevTools' `config.yml`.
- Add `--debug` to CLI commands for lifecycle, discovery, dependency-selection and artifact diagnostics.
- Keep DEBUG disabled by default and keep CLI JSON output on stdout without diagnostic noise.
- Add diagnostics across folder plugin discovery, shared virion resolution, PHAR builds, extraction and background worker jobs.
- Refresh the locked Symfony Filesystem dependency to `v6.4.50` to include its upstream security fix.

## Compatibility and upgrade notes

- This is an additive, backward-compatible release for the existing v3 workflow channel.
- Existing server configurations continue to run with DEBUG disabled.
- The CLI JSON schema and normal server command output are unchanged.
- PHAR creation still requires PHP to run with `phar.readonly=0`. Loading an existing PHAR does not.
