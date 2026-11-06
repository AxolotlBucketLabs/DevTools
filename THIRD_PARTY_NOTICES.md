# Third-party notices

## nikic/php-parser

DevTools uses and bundles `nikic/php-parser` for syntax-aware namespace shading. The bundled source is loaded through DevTools' private namespace at runtime so it cannot mix with another plugin's global parser version.

- Project: https://github.com/nikic/PHP-Parser
- Locked version: see `composer.lock`
- License: BSD-3-Clause
- Copyright: Nikita Popov and contributors

The complete upstream license is installed at `vendor/nikic/php-parser/LICENSE` and included at the same path in the DevTools PHAR.

## Reference projects

The following projects were inspected for behavior and architecture. No source from them is included in this implementation. These entries record research provenance rather than redistributed-code notices.

- pmmp/DevTools `stable` at `37a4db84df23f26f26fa4d1431abc279e99c0540` - LGPL-3.0-or-later source headers
- poggit/devirion `pm5` at `cf0d009b3b9120c16ef4663e7ea02257a66dc8ea` - Apache-2.0
- SOF3/pharynx `0.3.8` at `7d82d3ed471174735ec4f6c43a4836b1c3fef66d` - Apache-2.0

Additional dependency and CI references:

- poggit/devirion at `4fa0f581eac0391fae8c8cd51ba667ceaa19b2e7` - shared discovery/loading behavior. See [dependency evidence](docs/dependencies.md).
- axolotl-pm/pmmp-plugin-actions at `1280814dfdab491b8cec1defd167a1cc3aefe6c7` - build, tag validation and nightly publication behavior. See [Actions decisions](docs/github-actions.md).
- Composer 2.8.12 - lockfile content-hash behavior used to detect stale root metadata. Composer remains the package solver.
- SOF3/await-generator 3.6.1 at `90f4dda776eaf9bcb4693599c61c42e4c584a73f` - public Composer v3 preparation and shading probe. It is not bundled with DevTools. Preparation passed. Shading rejected an unsupported dynamic class reference.

Development tests use Axolotl-PM under LGPL-3.0, PHPUnit under BSD-3-Clause, PHPStan under MIT, and PHP CS Fixer under MIT. These development packages are not bundled in the release PHAR.
