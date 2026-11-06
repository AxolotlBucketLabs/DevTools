# Third-party notices

## nikic/php-parser

DevTools uses and bundles `nikic/php-parser` for syntax-aware namespace shading. The bundled source is loaded through DevTools' private namespace at runtime so it cannot mix with another plugin's global parser version.

- Project: https://github.com/nikic/PHP-Parser
- Locked version: see `composer.lock`
- License: BSD-3-Clause
- Copyright: Nikita Popov and contributors

The complete upstream license is installed at `vendor/nikic/php-parser/LICENSE` and included at the same path in the DevTools PHAR.
