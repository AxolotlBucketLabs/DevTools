# DevTools 2.0.2

This patch release keeps the v2 workflow contract while tightening virion autoload validation and the security posture of the maintained GitHub Actions workflows.

## Fixes

- Virion classes declared only in `.inc` or `.phtml` files are now rejected unless a matching `.php` autoload path or validated `.php` shim exists. This prevents a source folder from being reported as loaded while PocketMine's class loader cannot find the class.
- Maintained workflows pin `actions/checkout`, `actions/cache`, and artifact actions to immutable commit SHAs. All checkouts disable credential persistence so project build steps do not inherit a writable GitHub token configuration.
