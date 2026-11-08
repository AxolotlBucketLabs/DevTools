# Axolotl-PM runtime validation

DevTools supports Axolotl-PM as its runtime target. Structural validation, PHPStan,
unit tests and PHAR construction do not prove that a plugin enables successfully.
The runtime smoke harness starts real isolated Axolotl-PM servers to exercise that
additional boundary.

## Verified target

- Axolotl-PM **5.51.1**, commit
  **`e881fc1bc1c401545baf6bd1f824e41ff3f57bd1`**, the development dependency in
  DevTools' `composer.lock`.
- Windows x64, **PHP 8.4.27 ZTS** with Axolotl-PM's required extensions, including
  `pmmpthread`. PHP 8.4 is the source compatibility baseline. The complete CI
quality gate also runs on PHP 8.5. This runtime result does not claim an
executed PHP 8.5 server test.

## Reproduce

Use an Axolotl-PM-compatible PHP binary as `php` in the commands below. A generic
PHP installation without `pmmpthread` and the other server extensions cannot run
this test. Install DevTools dependencies from its committed lockfile first.

Prepare an isolated copy of the locked server source and install the server's own
locked runtime dependencies. PowerShell example from the DevTools checkout.

```powershell
composer install --no-interaction --no-scripts --no-plugins
$runtimeServer = Join-Path $env:TEMP ('DevTools-Axolotl-PM-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $runtimeServer
Copy-Item vendor/axolotl-pm/pocketmine-mp/* -Destination $runtimeServer -Recurse
composer install "--working-dir=$runtimeServer" --no-dev --no-interaction --no-scripts --no-plugins
php -d phar.readonly=0 bin/runtime-smoke.php "--server=$runtimeServer"
```

The destination must be a new directory **outside any Git checkout**. Axolotl-PM's
bootstrap compares its current Git revision with Composer's installed root
reference. A copied server nested inside the DevTools checkout can accidentally
inherit that checkout's revision, then refuse to boot after a DevTools commit.
Use the fresh temporary directory above and run `composer install` there.
`dump-autoload` alone may retain a stale root reference when reusing a copied
`vendor/` directory. No package update or server source patch is needed.

The server has its own Composer
autoloader. It does not inherit DevTools' developer autoloader. The harness reads
the Axolotl-PM source commit from DevTools' committed `composer.lock`, then checks
the installed revision, source and resource hashes and server lockfile before
running. Windows checkout line endings are normalized only for Markdown, JSON,
YAML and the server lockfile when their bytes differ. Preparing dependencies is
an explicit network operation. The harness does not download dependencies or
change the prepared server source.

Each run creates a unique `build/runtime-smoke-*` directory, separate data and
plugin directories, and selects RakNet bound to loopback with an ephemeral port.
NetherNet signaling and client connections are outside this plugin-loading test.
Usage reporting, crash reporting and the updater are disabled. Existing server
data and plugin directories are never used. Processes have time limits and are
stopped after the assertions complete. Inspect `target.json`, `result.json`,
each `console.log`, and each `*.passed` marker. Failed runs retain their logs and
inputs and return a nonzero exit code. `result.json` is written only when every
scenario passes.

## Executed scenarios

| Scenario | Installed inputs | Runtime assertions |
| --- | --- | --- |
| `shared-folder` | DevTools PHAR, three fixture plugin folders and one virion folder | Plain plugin loading, dependency order, library calls, resources, shared types and static state, async calls |
| `shared-phar` | DevTools PHAR, the same fixture plugins and a virion PHAR | The same checks through the PHAR virion loader |
| `shared-composer-folder` | Composer-locked fixture plugins and a matching local source folder without preparation | Shared runtime and async calls through a Composer package mapped to its local manifest name |
| `shared-composer-mixed` | A prepared Composer parent and a local source dependency | Transitive alias resolution and parent-to-child calls on the main thread and async workers |
| `shared-composer-local-parent` | A local parent and its prepared Composer dependency | Local-to-prepared dependency edges and the same runtime assertions |
| `private-phar` | Three built fixture PHARs without DevTools, source or development virions | Plugin loading and dependency order, two private library versions, resources, separate types and static state, async calls |

The first two consumers deliberately use the same library antigen. In shared
mode they accept each other's library objects and observe the same main-thread
static counter. In private PHAR mode the library types differ, passing the object
to the other consumer's typed method raises `TypeError`, and each counter starts
independently. Worker changes to a library static counter leave the main-thread
counter unchanged. The fixture treats these differences as the intended contract.

Bundled library classes therefore must not be exposed as a shared public API
between independently built plugins. Use an explicitly external plugin or API
dependency for that contract. PHP worker memory also does not become shared just
because workers use the same class loader. Transfer appropriate serializable
results through the runtime's async APIs.

## Source audit and limits

The [upstream folder loader](https://github.com/pmmp/DevTools/blob/37a4db84df23f26f26fa4d1431abc279e99c0540/src/FolderPluginLoader.php)
audited at commit `37a4db84df23f26f26fa4d1431abc279e99c0540`
registers its source root with the server's `ThreadSafeClassLoader`. DevTools
retains that integration and adds its own manifest and path diagnostics.
Axolotl-PM's [pinned plugin manager](https://github.com/axolotl-pm/PocketMine-MP/blob/e881fc1bc1c401545baf6bd1f824e41ff3f57bd1/src/plugin/PluginManager.php)
owns API compatibility and ordering for hard and soft dependencies and enable failures.
DevTools does not implement a parallel plugin manager.
The [pinned thread-safe class loader](https://github.com/axolotl-pm/PocketMine-MP/blob/e881fc1bc1c401545baf6bd1f824e41ff3f57bd1/src/thread/ThreadSafeClassLoader.php)
uses shared synchronized lookup tables. The runtime scenarios verify actual
worker autoloading. They do not exhaust every possible registration timing.

These are synthetic plugins executing on the real server. They are not client
gameplay tests, production plugin compatibility certification, an exhaustive
concurrency test or proof that every PHP dynamic class reference is shadeable.
Transitive-resolution failures, malformed manifests, namespace collisions and
output preservation are covered separately by focused unit and behavior tests.
Run those and the complete quality suite as well when changing the relevant
modules. Users building ordinary plugins do not need to run this repository's
entire test suite before every build.
