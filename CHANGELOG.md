# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [2.0.0]

A rewrite. See [UPGRADE.md](UPGRADE.md) for breaking changes.

### Added

- Monitors grouped into categories, checked lazily and rated `optimal`, `acceptable`, `degraded` or `failed`, with a `Probe` for degrading a rating, notes and metadata.
- Response time `thresholds` in milliseconds, with `otherwise` for anything slower.
- Built-in `file`, `http`, `pdo`, `cache`, `oauth_token` and `callback` monitor types, and `SystemStatus::extend()` for registering more.
- `MakesHttpRequests` for monitors of HTTP APIs, which keeps query strings and credentials out of error messages.
- Laravel integration: auto-discovered service provider and facade, publishable config, `database`, `cache`, `disk` and `queue` monitor types, and monitors built through the container.
- `/monitors/heartbeat`, `/monitors/ping` (load balancers, cached per server for 10 seconds by default) and `/monitors/status` (detailed JSON, protected by token, IP allow-list or environment).
- `php artisan system-status:check` for deploy pipelines.

[Unreleased]: https://github.com/oilstone/system-status/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/oilstone/system-status/releases/tag/v2.0.0
