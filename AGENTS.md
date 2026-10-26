# Contributor entrypoint

This is a PHP 8.4+ and SQLite 3.45+ publishing application. Preserve the WordPress naming conventions and procedural facades. Avoid unrelated architecture rewrites.

## Commands

- Install development dependencies: `composer install`.
- Syntax, PHPStan level 5, formatting, and PHP smoke tests: `composer check`.
- Real HTTP workflows: `python3 tests/http-smoke.py /absolute/path/to/php`.
- Format source: `composer format:fix`, then rerun the checks.

## Done

The affected workflow must work from a blank database. Run the relevant checks and report their actual results. Do not mask analysis errors with a generated baseline or broad suppressions. Keep executable side effects out of PHPStan's bootstrap. Packaging must exclude installed databases, credentials, maintenance barriers, logs, vendor packages, and caches. Include `composer.lock` so development tooling is reproducible.

## Scope and documentation

See [docs/QUALITY.md](docs/QUALITY.md) for gate scope, [docs/INSTALL.md](docs/INSTALL.md) for deployment and blank setup, and [docs/INVARIANTS.md](docs/INVARIANTS.md) for persistence and security boundaries. Never represent the formatting subset as full WordPress standards compliance. The bounded CLI runner and fixed render/transition job handlers are implemented. Preserve lease fencing and atomic acknowledgement. See docs/RELEASE_PLAN.md for deliberately excluded compatibility features.
