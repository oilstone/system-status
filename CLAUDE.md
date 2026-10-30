# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`oilstone/system-status`: a PHP 8.2+ library of health checks ("monitors") for an application's dependencies, with an optional Laravel 12+ integration (ping/status/heartbeat routes, a console command, a facade). README.md is the user-facing reference for config options and monitor types, UPGRADE.md covers 1.x → 2.0, and CHANGELOG.md records releases; keep them in sync when changing behaviour or options.

## Commands

```bash
composer install
composer check                                   # lint + analyse + test, as CI runs them
composer test                                    # all PHPUnit suites
vendor/bin/phpunit --testsuite Unit              # framework-agnostic core
vendor/bin/phpunit --testsuite Laravel           # Orchestra Testbench feature tests
vendor/bin/phpunit --filter test_thresholds_rate # a single test by name
composer analyse                                 # PHPStan level 8 (phpstan.neon.dist)
composer format                                  # Pint with the house style in pint.json
```

Guzzle, Laravel and Testbench are dev dependencies only; in production they are optional (`suggest`), so code outside `Integrations/Laravel` and `Concerns/MakesHttpRequests` must not depend on them. CI (`.github/workflows/tests.yml`) runs every supported PHP version against Laravel 12 and 13, plus Pint and PHPStan on the latest. `pint.json` keeps this codebase's style (`! $x`, spaced concatenation, multi-line empty bodies), which differs from Pint's current Laravel preset.

Testing notes:
- Laravel tests extend `tests/Laravel/TestCase.php`. Config read while the package boots (monitors, routes) is set with `$this->monitors([...])` or `$this->rebootWith([...])`, which refresh the application, so set up spies such as `Log::spy()` after calling them.
- HTTP monitors are tested without a network by passing a Guzzle `MockHandler` stack as `options.handler`.

## Architecture

Core (`src/`, framework-agnostic):

- `SystemStatus` stores monitor **definitions** per category, keyed as given (string keys, or numbered in order), and builds them lazily on `check()`. A monitor's id is `{category}.{key}`; `hasMonitor()`, `checkMonitor()` and `make()` take one, and `locate()` tries each category as a prefix because categories may contain dots. Definitions may be a `Contracts\Monitor` instance, a closure returning one, a registered type or class name, or an array with `class` or `type` plus options. `run()` wraps building, checking and rating each monitor in try/catch so any failure (including a monitor that can't be constructed) becomes a `Rating::Failed` `Result` instead of an exception. Only `UnknownCategory` escapes `check()`.
- `MonitorFactory` maps `type` strings (normalised to snake_case) to classes or closures; `extend()` adds/overrides types. `nameFor()` derives a display name without constructing the monitor (reads the class's default `$name` via reflection) for definitions that fail to build.
- `Monitors\Monitor` is the base class: constructor takes `array $config`, validates `$required` keys, then calls `configure()`. `rate()` turns duration (ms) into a `Rating` via `thresholds`/`otherwise`.
- `Probe` is passed to `check()`; monitors throw to fail, and use the probe to worsen the rating, add notes or metadata. Ratings only ever get worse.
- `Rating` is an int-backed enum where higher = worse, so comparisons are numeric (`isAtLeast`, `worst`). `Rating::parse()` accepts the enum, its int value, or a case-insensitive name, which is how config strings like `'degraded'` are read.
- Final rating of a result = worst of the probe's rating and the duration-based rating. `Report` aggregates results; pass/fail is always relative to a `fail_at` rating.
- `Probe::run()` checks and rates one built monitor; `SystemStatus::run()` and `Watcher` both use it, so watches rate checks exactly as the status does.
- Watches: `Watch` is the validated value object (`Watch::fromArray()`; unknown keys become handler options). Its `monitor` is a definition or the id of a status monitor, so `Watcher` takes the `SystemStatus` and builds through `SystemStatus::make()`. `Watcher::run()` builds the monitor and handler first (either failing throws without touching state), applies an override from `WatchStore` or runs the check, updates the consecutive counts and up/down state, saves, then calls the `Contracts\WatchHandler` on every run. A handler exception is rethrown as `WatchHandlerFailed` carrying the outcome; the state stays saved. `WatchStore` keeps state and overrides in PSR-16 under `system-status.watch.{name}.*`; override expiry is the cache TTL.
- `Concerns\RatesUsage` rates quota usage; it is analysed by PHPStan through `tests/Fixtures/QuotaMonitor.php`, since nothing in `src` uses it.
- `Concerns\MakesHttpRequests` is the Guzzle helper used by `Http`, `OAuthToken` and custom API monitors. It reduces every URL in error messages, including those inside Guzzle's own messages, to host, port and path (`describeUrl`, `redactUrls`) so credentials never leak into reports.

Laravel integration (`src/Integrations/Laravel/`):

- `SystemStatusServiceProvider` registers `SystemStatus` as a singleton built with `ContainerMonitorFactory`, adds the Laravel-specific types (`database`, `cache`, `disk`, `queue`) and loads `system-status.monitors` from config. Note the Laravel `cache` type (`CacheStore`) replaces the core PSR-16 `cache` type.
- `ContainerMonitorFactory` builds monitor classes through the container, passing options as the named `config` parameter. Monitors that need injected dependencies must therefore keep the first constructor parameter named `$config` (see `Monitors/Database.php`).
- Routes are registered outside the `web` group (no sessions) and skipped when routes are cached. `PingController` must always return plain `OK`/`FAIL` and never throw, including from its logging or its result cache (cached per server via a hostname in the key; any cache error falls back to checking live). `StatusController` returns JSON, 422 for bad `categories`/`fail_at`, and is guarded by `AuthorizeStatusRequest` (public environment, token, or IP/CIDR allow-list). `DashboardController` renders the page (`resources/views/dashboard.blade.php` with `partials/`, publishable) behind the same middleware; both read the query through `Http\StatusQuery`. With watches defined it leads with their stored state and never runs monitors on load; its `run()` action (`/dashboard/run`, `run.blade.php`) runs one monitor, a watch's monitor (as its status monitor when linked), every watch's (`watch=*`, Check all) or all monitors, never touching watch state, and returns `[data-monitor]` rows and `[data-check-for]` results that the page swaps in. Without watches it checks every monitor on load. The view is self-contained (inline CSS/JS, no CDNs, so it works during outages); it refreshes by fetching its own URL and replacing each `[data-region]` inside `#system-status` except those marked `data-keep` (on-demand monitors), keeping viewer-toggled `<details data-section>` and filled `[data-check-slot]`s. Live monitors (`[data-live]` toggles, shown only when `data-live-seconds` is set, i.e. with watches) are re-run by the script on that interval and kept in `?live=`. Runs are tracked by the monitors they cover (`data-run-key`: a monitor id, `*` for all, `watch:{name}` for a watch's own monitor, `watch:*` for every watch's), and live runs skip any that overlap one still in flight. Without watches the rows have no buttons and Run all is a refresh, so the report-backed headline can't go stale. Keep those hooks if you change the markup. Presentation logic lives in `DashboardController` (`summariseWatches()`, `summariseMonitors()`, `describeResult()`), and the client-side pill summaries in the view's script mirror `summariseMonitors()`.
- Watches: `Watches\WatchRegistry` reads `system-status.watches.definitions` (and strips the Laravel-only `cron`), `Watches\ContainerWatcher` builds handlers through the container and dispatches `MonitorWentDown`/`MonitorRecovered` on transitions, even when the handler throws. `system-status:watch` holds a cache lock per watch and, given `--occurrence` (the minute the scheduler passes), claims that minute in the watch store so each scheduled run happens once even when the default cache that `onOneServer()` uses isn't shared; `system-status:force` sets overrides. The provider schedules each watch with `callAfterResolving(Schedule::class)`, naming its mutex `system-status:watch:{name}` because the command line changes every minute (`schedule:finish` matches background runs by that name), with the overlap expiry tied to `WatchCommand::LOCK_SECONDS`. The status JSON and dashboard only read watch state from the store, never run checks, and must not let watches or a broken store affect pass/fail or the ping.
- Config must stay cacheable: definitions in `config/system-status.php` are plain arrays or type/class name strings, never closures or objects.

## Conventions

- New built-in monitor types are registered in `MonitorFactory::$types` (or in the service provider for Laravel-only ones), documented with an `Options:` docblock on the class, and added to the README tables.
- Error messages in results may be shown publicly when `expose_errors` is on, so avoid including secrets, full URLs with query strings, or credentials in failure messages.
