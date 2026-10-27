# Upgrade guide

## From 1.x to 2.0

2.0 is a rewrite and requires PHP 8.1+ (and Laravel 10+ for the Laravel integration). Breaking changes:

- `SystemStatus` is now an instance rather than static methods. In Laravel, use the facade or inject it.
- `isSystemOkay()` → `passes()`. `scores()` and `errors()` → `check()`, which returns a `Report` (`toArray()`, `failures()`, `rating()`).
- `StatusCode` is replaced by the `Rating` enum.
  - The status/score split is gone: a result is rated, and `fail_at` decides what counts as failing.
- `expectations.response_within` → `thresholds`, now genuinely in milliseconds. 1.x measured whole seconds, so its thresholds effectively never triggered. Invalid thresholds now fail the monitor rather than being ignored.
- Monitor types:
  - `mysql` → `pdo` (any driver), or `database` in Laravel.
  - `redis` → `cache` with a PSR-16 store, or `store` name in Laravel.
  - `bearerToken` → `oauth_token`, with `auth_url` renamed to `token_url`. To check a pre-issued `token` against a `verify_url`, use the `http` type with an `Authorization` header in `options.headers`.
  - `file` takes `path` and `checks` rather than `file_path` and `test_type`.
- Custom monitors implement `check(Probe $probe)` rather than `executeAction()`, and extend `Monitors\Monitor` with an optional config array.
- Requesting an unknown category throws instead of passing.
- Laravel apps no longer need their own monitor controllers, routes or service provider.
