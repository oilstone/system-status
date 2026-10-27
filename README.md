# system-status

Health checks for the things an application depends on, reported as a pass/fail ping for load balancers, a detailed JSON report for uptime monitors and people, and a console command for deploy pipelines.

Requires PHP 8.1+. The Laravel integration supports Laravel 10 and later.

## Concepts

- **Monitor**: one check against one dependency. It throws to fail, and can use the `Probe` it is given to degrade its rating, add notes or attach metadata without failing.
- **Category**: a named group of monitors, such as `connections` or `services`. Checks run per category, so a load balancer can be pointed at the categories that should take a server out of rotation while third-party services are reported elsewhere.
- **Rating**: `optimal`, `acceptable`, `degraded` or `failed`. A successful check is rated by its response time against the monitor's thresholds, then made worse by anything the monitor reported through the probe.
- **Report**: the results of one run. It passes when no result is rated at or beyond the chosen `fail_at` rating (default `failed`).

Monitors are only built when a check runs, and every run builds fresh results, so registering monitors costs nothing on ordinary requests and long-running processes never serve stale results. A monitor that cannot be built, for example because configuration is missing, becomes a failed result rather than breaking the report.

## Laravel

The service provider and `SystemStatus` facade are auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag=system-status-config
```

Define monitors by category in `config/system-status.php`:

```php
'monitors' => [
    'deployment' => [
        ['type' => 'file', 'path' => base_path('.deployed')],
    ],
    'connections' => [
        ['type' => 'database', 'connection' => 'mysql', 'thresholds' => [200 => 'optimal', 1000 => 'acceptable']],
        ['type' => 'cache', 'store' => 'redis'],
    ],
    'services' => [
        ['class' => App\Monitors\SalesforceMonitor::class, 'client_id' => env('SALESFORCE_CLIENT_ID')],
    ],
],

'ping' => [
    'categories' => ['deployment', 'connections'],
],
```

Definitions are plain arrays (or a bare type or class name, such as `'database'`), so the config can be cached. Monitor classes are built through the container: their constructor receives the options as `$config` and can type-hint any other dependency.

### Routes

Registered under the `routes.prefix` (default `monitors`), outside the `web` middleware group so polling never creates sessions.

| Route | Purpose | Response |
| --- | --- | --- |
| `GET /monitors/heartbeat` | The app boots and serves requests | `OK` 200 |
| `GET /monitors/ping` | Load balancer health check over `ping.categories` | `OK` 200 or `FAIL` 503 |
| `GET /monitors/status` | Full JSON report, access controlled | 200 when passing, 503 when failing |

The ping outcome is cached per server for `ping.cache_seconds` (default 10) in `ping.cache_store` (`SYSTEM_STATUS_CACHE_STORE`, default `file`), so frequent or malicious requests can't make every hit query your dependencies. Keep the store local to each server, so a cache outage can't slow the ping down, and set `cache_seconds` to `0` to always check live. Failures are only logged when the checks actually run.

`/status` accepts `?categories=services,connections` and `?fail_at=degraded`, so an external uptime monitor can alert on third-party services or slowness without affecting the load balancer.

Access to `/status` is granted when any of these apply:

- the request sends `status.token` (`SYSTEM_STATUS_TOKEN`) as a bearer token, an `X-Status-Token` header or a `?token=` parameter;
- the client IP matches `status.allowed_ips` (`SYSTEM_STATUS_ALLOWED_IPS`, comma separated, CIDR ranges allowed). This relies on `TrustProxies` trusting your load balancer;
- the environment is listed in `status.public_environments` (default `local`).

Set `status.expose_errors` to `false` to replace error messages with "Check failed".

Failed pings are logged as warnings with the failing monitors and their errors, through Laravel's logger (or `ping.log_channel`).

### Console

```bash
php artisan system-status:check
php artisan system-status:check services --fail-at=degraded
```

Prints a table and exits non-zero when the report fails, which suits post-deploy checks.

### Laravel monitor types

| Type | Options |
| --- | --- |
| `database` | `connection`, `query` (default `select 1`) |
| `cache` | `store`, `key_prefix`, `ttl` |
| `disk` | `disk`, `directory` |
| `queue` | `connection`, `queue`, `degrade_waiting`, `max_waiting` (waiting job counts), `degrade_age`, `max_age` (seconds the oldest waiting job has waited) |

The queue monitor counts only jobs waiting to be picked up, not delayed or reserved ones, where the driver can tell them apart. Job age limits need a driver that reports its oldest pending job.

Database connect timeouts come from the connection's PDO options, e.g. `PDO::ATTR_TIMEOUT` in `config/database.php`.

## Built-in monitor types

| Type | Options |
| --- | --- |
| `file` | `path`, `checks` (`exists`, `readable`, `writable`), `max_age` (seconds) |
| `http` | `url`, `method`, `expect_status`, `expect_json` (dot paths to exact values) |
| `pdo` | `pdo` (instance or closure) or `dsn`, `username`, `password`, `options`; `query`, `timeout` |
| `cache` | `store` (PSR-16 cache or closure), `key_prefix`, `ttl` |
| `oauth_token` | `token_url`, `grant_type` (`client_credentials`, `password` or `refresh_token`), `client_id`, `client_secret` (optional for public clients outside `client_credentials`), `client_auth` (`post` or `basic`), `username`, `password`, `refresh_token`, `scope`, `token_parameters`, `verify_url` |
| `callback` | `callback`: callable or invokable class (built through the container in Laravel), given `(Probe $probe, array $config)`; fails if it throws or returns `false` |

Every type also accepts:

- `name`
- `thresholds`: response time limits in milliseconds mapped to ratings, e.g. `[200 => 'optimal', 1000 => 'acceptable']`
- `otherwise`: the rating for anything slower than every threshold (default `degraded`)

HTTP-based monitors also accept `timeout` (default 5s), `connect_timeout` (default 3s) and `options` (Guzzle client defaults such as `headers`, `auth`, `proxy` or `verify`). They require `guzzlehttp/guzzle`.

## Writing a monitor

Extend `Oilstone\SystemStatus\Monitors\Monitor` and implement `check()`. Use `MakesHttpRequests` for HTTP APIs, or extend `OAuthToken` for APIs behind OAuth.

```php
use Oilstone\SystemStatus\Concerns\MakesHttpRequests;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

class ExampleApiMonitor extends Monitor
{
    use MakesHttpRequests;

    protected string $name = 'Example API';

    protected array $required = ['token'];

    protected array $thresholds = [1000 => 'optimal', 3000 => 'acceptable'];

    public function check(Probe $probe): void
    {
        $account = $this->requestJson('GET', 'https://api.example.com/account', [
            'headers' => ['Authorization' => 'Bearer ' . $this->config['token']],
        ]);

        $probe->meta('plan', $account['plan']);

        if ($account['quota_remaining'] < 100) {
            $probe->degrade('Quota is nearly used up');
        }
    }
}
```

- `$required` keys are validated when the monitor is built.
- Override `configure()` for anything more involved.
- Call `$this->fail()` (or throw anything) to fail the check.
- Failed HTTP responses include the API's own error message where it can be found.

To make a monitor available as a `type`, register it:

```php
SystemStatus::extend('example_api', ExampleApiMonitor::class);
SystemStatus::extend('example_api', fn (array $config) => new ExampleApiMonitor($config));
```

## Without Laravel

```php
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\SystemStatus;

$status = (new SystemStatus())
    ->add(['type' => 'pdo', 'dsn' => 'mysql:host=db;dbname=app', 'username' => 'app', 'password' => 'secret'], 'connections')
    ->add(['type' => 'http', 'url' => 'https://search.internal/health', 'expect_json' => ['ok' => true]], 'connections')
    ->add(fn () => new ExampleApiMonitor(['token' => getenv('EXAMPLE_TOKEN')]), 'services');

$report = $status->check(['connections']);

http_response_code($report->passes() ? 200 : 503);
echo json_encode($report->toArray(Rating::Failed, exposeErrors: false));
```

Requesting a category that has no monitors throws `UnknownCategory` rather than silently passing.

## Upgrading from 1.x

2.0 is a rewrite. Breaking changes:

- `SystemStatus` is now an instance rather than static methods. In Laravel, use the facade or inject it.
- `isSystemOkay()` → `passes()`. `scores()` and `errors()` → `check()`, which returns a `Report` (`toArray()`, `failures()`, `rating()`).
- `StatusCode` is replaced by the `Rating` enum.
  - The status/score split is gone: a result is rated, and `fail_at` decides what counts as failing.
- `expectations.response_within` → `thresholds`, now genuinely in milliseconds. 1.x measured whole seconds, so its thresholds effectively never triggered.
- Monitor types:
  - `mysql` → `pdo` (any driver), or `database` in Laravel.
  - `redis` → `cache` with a PSR-16 store, or `store` name in Laravel.
  - `bearerToken` → `oauth_token`.
  - `file` takes `path` and `checks` rather than `file_path` and `test_type`.
- Custom monitors implement `check(Probe $probe)` rather than `executeAction()`, and extend `Monitors\Monitor` with an optional config array.
- Requesting an unknown category throws instead of passing.
- Laravel apps no longer need their own monitor controllers, routes or service provider.
