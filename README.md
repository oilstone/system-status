# system-status

Health checks for the things an application depends on, reported as a pass/fail ping for load balancers, a detailed JSON report for uptime monitors and people, and a console command for deploy pipelines.

Requires PHP 8.2+. The Laravel integration supports Laravel 12 and later.

## Concepts

- **Monitor**: one check against one dependency. It throws to fail, and can use the `Probe` it is given to degrade its rating, add notes or attach metadata without failing.
- **Category**: a named group of monitors, such as `connections` or `services`. Checks run per category, so a load balancer can be pointed at the categories that should take a server out of rotation while third-party services are reported elsewhere.
- **Rating**: `optimal`, `acceptable`, `degraded` or `failed`. A successful check is rated by its response time against the monitor's thresholds, then made worse by anything the monitor reported through the probe.
- **Report**: the results of one run. It passes when no result is rated at or beyond the chosen `fail_at` rating (default `failed`).
- **Watch**: a scheduled check of one monitor that tracks whether it is up or down over consecutive runs, and tells a handler, so you can alert or fail over. See [Watches](#watches).

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
        'salesforce' => ['class' => App\Monitors\SalesforceMonitor::class, 'client_id' => env('SALESFORCE_CLIENT_ID')],
    ],
],

'ping' => [
    'categories' => ['deployment', 'connections'],
],
```

Definitions are plain arrays (or a bare type or class name, such as `'database'`), so the config can be cached. Monitor classes are built through the container: their constructor receives the options as `$config` and can type-hint any other dependency.

Each monitor has an id made of its category and its key in that category: `services.salesforce` above, or `connections.0` for one in a plain list. Give a monitor a string key when something else refers to it, such as a [watch](#watches) or a link to it on the dashboard, so the id doesn't change when the list is reordered.

### Routes

Registered under the `routes.prefix` (default `monitors`), outside the `web` middleware group so polling never creates sessions.

| Route | Purpose | Response |
| --- | --- | --- |
| `GET /monitors/heartbeat` | The app boots and serves requests | `OK` 200 |
| `GET /monitors/ping` | Load balancer health check over `ping.categories` | `OK` 200 or `FAIL` 503 |
| `GET /monitors/status` | Full JSON report, access controlled | 200 when passing, 503 when failing |
| `GET /monitors/dashboard` | Watches and monitors as a page for people, access controlled | HTML, 200 |
| `GET /monitors/dashboard/run` | Runs monitors for the dashboard's buttons, access controlled | HTML, 200 |

The ping outcome is cached per server for `ping.cache_seconds` (default 10) in `ping.cache_store` (`SYSTEM_STATUS_CACHE_STORE`, default `file`), so frequent or malicious requests can't make every hit query your dependencies. Keep the store local to each server, so a cache outage can't slow the ping down, and set `cache_seconds` to `0` to always check live. Failures are only logged when the checks actually run.

`/status` accepts `?categories=services,connections` and `?fail_at=degraded`, so an external uptime monitor can alert on third-party services or slowness without affecting the load balancer.

Access to `/status` is granted when any of these apply:

- the request sends `status.token` (`SYSTEM_STATUS_TOKEN`) as a bearer token, an `X-Status-Token` header or a `?token=` parameter;
- the client IP matches `status.allowed_ips` (`SYSTEM_STATUS_ALLOWED_IPS`, comma separated, CIDR ranges allowed). This relies on `TrustProxies` trusting your load balancer;
- the environment is listed in `status.public_environments` (default `local`).

Set `status.expose_errors` to `false` to replace error messages with "Check failed".

Failed pings are logged as warnings with the failing monitors and their errors, through Laravel's logger (or `ping.log_channel`).

### Dashboard

`/monitors/dashboard` is a page to open in a browser. It follows the system's light or dark theme and refreshes itself in place every `dashboard.refresh_seconds` (default 30, `0` to stop), skipping refreshes while the tab is hidden and saying so if a refresh fails.

When [watches](#watches) are defined, they lead the page. The headline comes from their stored state ("Payments is down", "Search is overdue", "All watches up"), followed by counts of watches that are up, down and overdue, then each watch. The monitors are listed by category below, but the page **doesn't run them**, so opening it or leaving it open puts no load on your dependencies, and it stays fast during an outage. Use **Run now** on a monitor, **Run all**, or **Check now** on a watch, which runs the watch's monitor without changing the watch. Results stay on the page until the next run, through refreshes.

To keep monitors up to date, press **Live** in the Monitors header to run them all again at every refresh, or **Live** on a single monitor to follow just that one. Live runs use `dashboard.refresh_seconds` (every 30 seconds when refreshing is off), pause while the tab is hidden, and skip a monitor that is still running from any run: a live one, **Run now**, **Run all** or **Check now**. The URL records what is live (`?live=all`, or `?live=services.payments` for each monitor), so a screen left showing the dashboard can be bookmarked live.

Without watches, the page checks every monitor as it loads, and the headline comes from that report, as the `/status` JSON does. **Run all** then checks them again by refreshing the page, so the headline always matches the results; there are no per-monitor or live buttons, since everything runs at each refresh.

Each category of monitors can be opened or closed. A category starts closed unless it has a problem: a failing or degraded result, or a watch of one of its monitors that is down. Sections stay as you leave them when the page refreshes. A watch that [names a status monitor](#watches) links to that monitor's row, and the row shows which watches check it and their state.

It uses the same access rules as `/status`, so open it with `?token=` or from an allowed IP. It also honours the same `categories`, `fail_at` and `expose_errors` settings and query parameters, e.g. `/monitors/dashboard?token=…&categories=services`. Pages are sent with `Referrer-Policy: no-referrer` and `noindex`, so a token in the URL isn't leaked to other sites or search engines.

Set `dashboard.title` to change the heading (default "{app name} status"), add middleware with `routes.dashboard_middleware` (`routes.status_middleware` applies too), or set `dashboard.enabled` to `false` to remove the route. To change the page itself, publish the view:

```bash
php artisan vendor:publish --tag=system-status-views
```

### Console

```bash
php artisan system-status:check
php artisan system-status:check services --fail-at=degraded
```

Prints a table and exits non-zero when the report fails, which suits post-deploy checks.

### Watches

A watch checks one monitor on a schedule and tracks whether it is up or down, so you can alert someone or fail over to another provider when it goes down, and back when it recovers. Define watches in the `watches` section of `config/system-status.php`, keyed by name:

```php
'watches' => [
    'cache_store' => env('SYSTEM_STATUS_WATCH_CACHE_STORE'),
    'schedule' => true,
    'run_in_background' => env('SYSTEM_STATUS_WATCH_RUN_IN_BACKGROUND', true),
    'definitions' => [
        'payments' => [
            'monitor' => ['type' => 'http', 'url' => 'https://payments.example.com/health'],
            'failures' => 3,
            'recoveries' => 2,
            'handler' => App\Watches\FailOverPayments::class,
            'cron' => '*/2 * * * *',
            'fallback' => 'secondary',
        ],
    ],
],
```

| Option | Purpose |
| --- | --- |
| `monitor` | The id of a monitor in the `monitors` section, such as `services.payments`, or any monitor definition that section accepts: an array with `type` or `class`, or a type or class name (required) |
| `failures` | Consecutive failed checks before the watch is down (default 1) |
| `recoveries` | Consecutive passing checks before a down watch is up again (default 1) |
| `fail_at` | The rating that counts as failed (default `failed`) |
| `handler` | A class implementing `Contracts\WatchHandler`, built through the container |
| `cron` | When to run the watch (default every minute) |

Any other keys, like `fallback` above, are passed to the handler as its options. Watch names may contain letters, numbers, dots, dashes and underscores.

A watch can check a monitor you already report on, rather than defining its own copy, by naming its id:

```php
'monitors' => [
    'services' => [
        'payments' => ['type' => 'http', 'url' => 'https://payments.example.com/health'],
    ],
],

'watches' => [
    'definitions' => [
        'payments' => ['monitor' => 'services.payments', 'failures' => 3],
    ],
],
```

The watch then runs exactly the check the status runs. The dashboard links the watch and the monitor to each other, and **Check now** on the watch shows its result in the monitor's row too. The watch's entry in the `/status` JSON gives the id as `monitor`.

Each run checks the monitor just as the status does (its probe rating and response time thresholds, with any exception counting as failed) and compares the rating with `fail_at`. A watch starts up, goes down once `failures` consecutive checks fail, and comes back up once `recoveries` consecutive checks pass. The state is saved, then the handler is told the outcome of **every** run, not only changes, so it can reconcile idempotently and retry anything that failed last time:

```php
use Oilstone\SystemStatus\Contracts\WatchHandler;
use Oilstone\SystemStatus\WatchOutcome;

class FailOverPayments implements WatchHandler
{
    public function __construct(protected PaymentGateways $gateways)
    {
    }

    public function handle(WatchOutcome $outcome, array $options): void
    {
        $this->gateways->use($outcome->isDown() ? $options['fallback'] : 'primary');
    }
}
```

`WatchOutcome` has the `watch` name, the built `monitor`, the `state` and `previous` state, whether it `changed` (also `wentDown()` and `recovered()`), the `forced` state or null, the check's `result` (null when forced), the consecutive `failures` and `passes`, and `checkedAt`. A handler that throws doesn't undo the state change.

If a watch's monitor or handler can't be built, the run fails without changing the state or calling the handler, so a misconfiguration is never mistaken for an outage.

When a watch goes down or recovers, Laravel's `MonitorWentDown` or `MonitorRecovered` event is dispatched (`Integrations\Laravel\Events`), carrying the outcome as `$event->outcome`, so you can listen for changes instead of, or as well as, writing a handler.

State is kept in `watches.cache_store` (`SYSTEM_STATUS_WATCH_CACHE_STORE`, the default store when null). Unlike `ping.cache_store`, it must be shared by every server and support atomic locks, such as `redis`, `memcached` or `database`.

When `watches.schedule` is on (the default), each watch is added to Laravel's scheduler with its `cron`, on one server at a time and without overlapping, so make sure `schedule:run` is running. Each scheduled run also claims its minute in `watches.cache_store`, so it runs once across your servers even if Laravel's default cache, which `onOneServer()` relies on, isn't shared; otherwise every server would check it and the thresholds would be reached too soon.

Scheduled watches run in the background by default, so a check waiting out its timeouts doesn't hold up the other watches or the rest of your schedule. Laravel runs `schedule:finish` as each one ends; `schedule:run` doesn't wait for them or show their output, but the command still reports failures to your exception handler. Each watch's overlap mutex is named `system-status:watch:{name}`, so it holds across minutes even though each run's command line names its minute, and it expires after five minutes, with the command's own lock, so a crashed run doesn't block the watch for long. To run them one after another instead, set `watches.run_in_background` to false (`SYSTEM_STATUS_WATCH_RUN_IN_BACKGROUND=false`).

To run watches yourself instead, turn `watches.schedule` off and call the command:

```bash
php artisan system-status:watch            # every watch
php artisan system-status:watch payments   # one watch
```

Each watch runs under a cache lock, so a manual run can't race the scheduled one; if the watch is already running it is skipped. The command prints each outcome and exits non-zero, reporting the exception, if a monitor or handler couldn't be built or a handler threw.

To force a watch up or down regardless of its checks, for maintenance or a manual failover:

```bash
php artisan system-status:force payments down --minutes=30
php artisan system-status:force payments up
php artisan system-status:force payments clear
```

An override takes effect from the watch's next run, applies immediately without waiting for the thresholds, and lasts until cleared or for `--minutes`.

Watches appear on the dashboard and under `watches` in the `/status` JSON, read from the store without running any checks: each watch's `state`, consecutive `failures` and `passes`, `forced` state and `forced_until`, `checked_at`, its `last_check` (the `rating`, `duration_ms` and any `error`, following `expose_errors`; null before the first check or after a forced run), and the id of the status `monitor` it checks, or null. They never affect the status's pass/fail or the ping.

When the watches are scheduled, each also has a `schedule`: its `cron`, the `timezone` the scheduler reads it in (`app.schedule_timezone`, or `app.timezone`), `next_run_at`, and `overdue_since`, the run that should have followed the last one but more than five minutes later still hasn't. An overdue watch usually means `schedule:run` has stopped, so its state is out of date. The dashboard shows the schedule in words where it can ("Every 5 minutes", "Weekdays at 02:30 UTC"), when it runs next, and flags overdue watches.

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
- For an API with its own SDK, pass it `$this->sdkHttpClient()`: a Guzzle client with the monitor's `timeout`, `connect_timeout` and `options`, which leaves Guzzle's `http_errors` on, because SDKs rely on its exceptions.

To rate how much of a quota has been used, use `RatesUsage`. It reads `degrade_usage` and `max_usage` options (percentages from 0 to 100, or null to turn that limit off), with defaults from the monitor:

```php
use Oilstone\SystemStatus\Concerns\RatesUsage;

class ExampleQuotaMonitor extends Monitor
{
    use MakesHttpRequests, RatesUsage;

    public function check(Probe $probe): void
    {
        $usage = $this->requestJson('GET', 'https://api.example.com/usage');

        // Degrades or fails with e.g. "Daily API usage is at 83% (12500 of 15000)"
        $this->rateUsage($probe, 'Daily API', $usage['used'], $usage['limit']);
    }

    protected function configure(): void
    {
        $this->validateUsageLimits();
    }

    protected function usageDefaults(): array
    {
        return ['degrade_usage' => 80, 'max_usage' => 95];
    }
}
```

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

Watches work without Laravel too; schedule the runs yourself, e.g. with cron:

```php
use Oilstone\SystemStatus\Watch;
use Oilstone\SystemStatus\Watcher;
use Oilstone\SystemStatus\WatchState;
use Oilstone\SystemStatus\WatchStore;

$store = new WatchStore($psr16Cache); // shared by every server
$watcher = new Watcher($status, $store);

$outcome = $watcher->run(Watch::fromArray('payments', [
    'monitor' => ['type' => 'http', 'url' => 'https://payments.example.com/health'],
    'failures' => 3,
    'handler' => FailOverPayments::class, // constructed without arguments outside Laravel
]));

$store->force('payments', WatchState::Down, seconds: 1800);
$store->clear('payments');
```

A watch's `monitor` can be the id of a monitor added to `$status` with a key, e.g. `$status->add([...], 'services', 'payments')` and `'monitor' => 'services.payments'`. `$status->checkMonitor('services.payments')` checks one monitor on its own.

`Watcher::run()` throws when the monitor or handler can't be built (changing nothing), and throws `WatchHandlerFailed`, carrying the `outcome`, when the handler throws after the state was saved. Run each watch from one process at a time. `$store->get('payments')` returns the stored `WatchStatus`, including the last check's `rating`, `duration` and `error`.

## Upgrading from 1.x

2.0 is a rewrite. See [UPGRADE.md](UPGRADE.md) for the breaking changes, and [CHANGELOG.md](CHANGELOG.md) for what's new.

## Development

```bash
composer install
composer test       # PHPUnit: tests/Unit and tests/Laravel (Orchestra Testbench)
composer analyse    # PHPStan
composer lint       # Pint, in test mode; composer format applies fixes
composer check      # all three
```
