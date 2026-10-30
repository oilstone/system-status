# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Watches: scheduled checks of one monitor each, which go down after `failures` consecutive failed checks and back up after `recoveries` passing ones, and tell a `WatchHandler` the outcome of every run. State is kept in a shared PSR-16 cache (`WatchStore`), and a watch can be forced up or down, optionally for a limited time.
- Laravel: a `watches` config section, `php artisan system-status:watch` and `system-status:force`, scheduling of each watch on one server without overlapping (each scheduled run is claimed in the watch store, so it runs once even when the default cache isn't shared), handlers built through the container, `MonitorWentDown` and `MonitorRecovered` events, and watches on the dashboard and in the `/monitors/status` JSON.
- `RatesUsage` for monitors that rate quota usage against `degrade_usage` and `max_usage`.
- `MakesHttpRequests::sdkHttpClient()`, a Guzzle client for third-party SDKs with the monitor's timeouts and options.
- `Probe::run()`, which checks and rates a built monitor as the status does.

### Changed

- Requires PHP 8.2+, and Laravel 12+ for the Laravel integration. PHP 8.1 and Laravel 10 and 11 no longer receive security fixes.

### Removed

- The `queue` monitor's fallback for drivers without `pendingSize()` and `creationTimeOfOldestPendingJob()`, which every Laravel 12+ queue implements.

## [2.0.0]

A rewrite. See [UPGRADE.md](UPGRADE.md) for breaking changes.

### Added

- Monitors grouped into categories, checked lazily and rated `optimal`, `acceptable`, `degraded` or `failed`, with a `Probe` for degrading a rating, notes and metadata.
- Response time `thresholds` in milliseconds, with `otherwise` for anything slower.
- Built-in `file`, `http`, `pdo`, `cache`, `oauth_token` and `callback` monitor types, and `SystemStatus::extend()` for registering more.
- `MakesHttpRequests` for monitors of HTTP APIs, which keeps query strings and credentials out of error messages.
- Laravel integration: auto-discovered service provider and facade, publishable config, `database`, `cache`, `disk` and `queue` monitor types, and monitors built through the container.
- `/monitors/heartbeat`, `/monitors/ping` (load balancers, cached per server for 10 seconds by default) and `/monitors/status` (detailed JSON, protected by token, IP allow-list or environment).
- `/monitors/dashboard`: the status report as a self-refreshing page for people, with the same access rules as `/monitors/status` and a publishable view.
- `php artisan system-status:check` for deploy pipelines.

[Unreleased]: https://github.com/oilstone/system-status/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/oilstone/system-status/releases/tag/v2.0.0
