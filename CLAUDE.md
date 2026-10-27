# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`oilstone/system-status`: a PHP 8.1+ library of health checks ("monitors") for an application's dependencies, with an optional Laravel 10+ integration (ping/status/heartbeat routes, a console command, a facade). README.md is the user-facing reference for config options, monitor types and the 1.x → 2.0 upgrade notes; keep it in sync when changing behaviour or options.

## Commands

There is no test suite, linter or CI config in the repo, and `composer.json` has no dev dependencies. Guzzle and Laravel are only `suggest`ed, so a plain `composer install` cannot load `Concerns/MakesHttpRequests` or anything under `Integrations/Laravel`. To verify changes:

```bash
composer install
find src -name '*.php' -exec php -l {} \;
```

For runtime checks, require the package into a Laravel app (or install `guzzlehttp/guzzle`/`laravel/framework` locally without committing them) and exercise `php artisan system-status:check`.

## Architecture

Core (`src/`, framework-agnostic):

- `SystemStatus` stores monitor **definitions** per category and builds them lazily on `check()`. Definitions may be a `Contracts\Monitor` instance, a closure returning one, a class name, or an array with `class` or `type` plus options. `run()` wraps building, checking and rating each monitor in try/catch so any failure (including a monitor that can't be constructed) becomes a `Rating::Failed` `Result` instead of an exception. Only `UnknownCategory` escapes `check()`.
- `MonitorFactory` maps `type` strings (normalised to snake_case) to classes or closures; `extend()` adds/overrides types. `nameFor()` derives a display name without constructing the monitor (reads the class's default `$name` via reflection) for definitions that fail to build.
- `Monitors\Monitor` is the base class: constructor takes `array $config`, validates `$required` keys, then calls `configure()`. `rate()` turns duration (ms) into a `Rating` via `thresholds`/`otherwise`.
- `Probe` is passed to `check()`; monitors throw to fail, and use the probe to worsen the rating, add notes or metadata. Ratings only ever get worse.
- `Rating` is an int-backed enum where higher = worse, so comparisons are numeric (`isAtLeast`, `worst`). `Rating::parse()` accepts the enum, its int value, or a case-insensitive name, which is how config strings like `'degraded'` are read.
- Final rating of a result = worst of the probe's rating and the duration-based rating. `Report` aggregates results; pass/fail is always relative to a `fail_at` rating.
- `Concerns\MakesHttpRequests` is the Guzzle helper used by `Http`, `OAuthToken` and custom API monitors. It strips query strings from URLs in error messages (`describeUrl`) so credentials never leak into reports.

Laravel integration (`src/Integrations/Laravel/`):

- `SystemStatusServiceProvider` registers `SystemStatus` as a singleton built with `ContainerMonitorFactory`, adds the Laravel-specific types (`database`, `cache`, `disk`, `queue`) and loads `system-status.monitors` from config. Note the Laravel `cache` type (`CacheStore`) replaces the core PSR-16 `cache` type.
- `ContainerMonitorFactory` builds monitor classes through the container, passing options as the named `config` parameter. Monitors that need injected dependencies must therefore keep the first constructor parameter named `$config` (see `Monitors/Database.php`).
- Routes are registered outside the `web` group (no sessions) and skipped when routes are cached. `PingController` must always return plain `OK`/`FAIL` and never throw, including from its logging or its result cache (cached per server via a hostname in the key; any cache error falls back to checking live). `StatusController` returns JSON, 422 for bad `categories`/`fail_at`, and is guarded by `AuthorizeStatusRequest` (public environment, token, or IP/CIDR allow-list).
- Config must stay cacheable: definitions in `config/system-status.php` are plain arrays or type/class name strings, never closures or objects.

## Conventions

- New built-in monitor types are registered in `MonitorFactory::$types` (or in the service provider for Laravel-only ones), documented with an `Options:` docblock on the class, and added to the README tables.
- Error messages in results may be shown publicly when `expose_errors` is on, so avoid including secrets, full URLs with query strings, or credentials in failure messages.
