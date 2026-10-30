# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

2.0, a rewrite, available as `2.0.0-beta` pre-releases. See [UPGRADE.md](UPGRADE.md) for breaking changes from 1.x.

### Added

- Monitors grouped into categories, checked lazily and rated `optimal`, `acceptable`, `degraded` or `failed`, with a `Probe` for degrading a rating, notes and metadata. `Probe::run()` checks and rates a built monitor as the status does.
- Response time `thresholds` in milliseconds, with `otherwise` for anything slower.
- Built-in `file`, `http`, `pdo`, `cache`, `oauth_token` and `callback` monitor types, and `SystemStatus::extend()` for registering more.
- `MakesHttpRequests` for monitors of HTTP APIs, which keeps query strings and credentials out of error messages, and its `sdkHttpClient()`, a Guzzle client for third-party SDKs with the monitor's timeouts and options.
- `RatesUsage` for monitors that rate quota usage against `degrade_usage` and `max_usage`.
- Watches: scheduled checks of one monitor each, which go down after `failures` consecutive failed checks and back up after `recoveries` passing ones, and tell a `WatchHandler` the outcome of every run. State is kept in a shared PSR-16 cache (`WatchStore`), along with the last check's rating, duration and error, and a watch can be forced up or down, optionally for a limited time.
- Laravel integration: auto-discovered service provider and facade, publishable config, `database`, `cache`, `disk` and `queue` monitor types, and monitors built through the container.
- `/monitors/heartbeat`, `/monitors/ping` (load balancers, cached per server for 10 seconds by default) and `/monitors/status` (detailed JSON, protected by token, IP allow-list or environment).
- `/monitors/dashboard`: a self-refreshing page for people, with the same access rules as `/monitors/status` and publishable views. When watches are defined it leads with them and runs monitors only from its **Run now**, **Run all**, **Check now** and **Check all** buttons (through `/monitors/dashboard/run`); otherwise it checks the monitors as it loads. Monitors can be made **Live**, all together or one by one, to run again at every refresh. Categories collapse when healthy.
- Monitor ids (`{category}.{key}`), from string keys in a category or a monitor's position in it. `SystemStatus::add()` takes a `key`, and `hasMonitor()`, `checkMonitor()`, `make()` and `definitions()` work with them. A watch's `monitor` can be the id of a status monitor, which links the two on the dashboard and in the `/status` JSON.
- `php artisan system-status:check` for deploy pipelines.
- Laravel watches: a `watches` config section, `php artisan system-status:watch` and `system-status:force`, handlers built through the container, `MonitorWentDown` and `MonitorRecovered` events, and watches on the dashboard and in the `/monitors/status` JSON, with their last check's error, their schedule and next run, and a warning when a scheduled run is more than five minutes overdue (`watches.overdue_warnings` turns it off where the scheduler doesn't run). Each watch is scheduled with its own `cron`, in the background by default (`watches.run_in_background`), on one server, and without overlapping under a mutex named `system-status:watch:{name}` that expires with the command's five-minute lock. Each scheduled run is also claimed in the watch store, so it runs once even when the default cache isn't shared.

### Changed

- Requires PHP 8.2+, and Laravel 12+ for the Laravel integration.

[Unreleased]: https://github.com/oilstone/system-status/compare/v1.0.0...HEAD
