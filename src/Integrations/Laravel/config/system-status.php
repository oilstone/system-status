<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitors
    |--------------------------------------------------------------------------
    |
    | Monitor definitions grouped by category. Each definition is a type or
    | monitor class name, or an array of options with a "class" or "type".
    |
    | Built-in types: database, cache, disk, queue, file, http, pdo,
    | oauth_token and callback. Register more with SystemStatus::extend().
    |
    | Every definition accepts "name", plus "thresholds" mapping response
    | times in milliseconds to ratings, e.g. [200 => 'optimal', 1000 =>
    | 'acceptable'], with "otherwise" rating anything slower (default
    | 'degraded').
    |
    */

    'monitors' => [
        // 'connections' => [
        //     ['type' => 'database', 'connection' => 'mysql'],
        //     ['type' => 'cache', 'store' => 'redis'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ping
    |--------------------------------------------------------------------------
    |
    | The public pass/fail endpoint for load balancers. Responds "OK" (200)
    | or "FAIL" (503). Only include categories whose failure means this
    | server should stop receiving traffic, or one outage elsewhere will
    | take every server out of rotation.
    |
    | fail_at is the rating at which a monitor counts as failing: optimal,
    | acceptable, degraded or failed.
    |
    | The outcome is cached for cache_seconds, per server, so frequent or
    | malicious requests cannot make every hit query your dependencies.
    | Use a store local to each server (the default "file") so a cache
    | outage cannot slow the ping down; set 0 to always check live.
    |
    */

    'ping' => [
        'categories' => null,
        'fail_at' => 'failed',
        'cache_seconds' => 10,
        'cache_store' => env('SYSTEM_STATUS_CACHE_STORE', 'file'),
        'log_failures' => true,
        'log_channel' => env('SYSTEM_STATUS_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    |
    | The detailed JSON report. Responds 200 when passing and 503 otherwise.
    | Accepts ?categories=a,b and ?fail_at=degraded to narrow the check.
    |
    | Access is granted to requests that send the token (Authorization:
    | Bearer, an X-Status-Token header or ?token=), come from an allowed IP
    | or CIDR range, or reach an environment listed as public.
    |
    */

    'status' => [
        'categories' => null,
        'fail_at' => 'failed',
        'token' => env('SYSTEM_STATUS_TOKEN'),
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('SYSTEM_STATUS_ALLOWED_IPS', ''))))),
        'public_environments' => ['local'],
        'expose_errors' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The status as a page for people to open in a browser. It shows the
    | same report as the status endpoint, with the same access rules,
    | categories, fail_at and expose_errors, and the same query string
    | options. It refreshes in place every refresh_seconds (0 to stop).
    | Publish the system-status-views tag to customise it.
    |
    */

    'dashboard' => [
        'enabled' => true,
        'title' => null, // Defaults to "{app.name} status"
        'refresh_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Watches
    |--------------------------------------------------------------------------
    |
    | Scheduled checks of single monitors that track whether each is up or
    | down, so a handler can alert or fail over. Definitions are keyed by
    | watch name (letters, numbers, dots, dashes and underscores) and take:
    |
    | - monitor: a monitor definition, as in the monitors section (required)
    | - failures: consecutive failed checks before it is down (default 1)
    | - recoveries: consecutive passing checks before it is up (default 1)
    | - fail_at: the rating that counts as failed (default 'failed')
    | - handler: a WatchHandler class, built through the container, that
    |   is told the outcome of every run, not only changes
    | - cron: when to run it (default every minute)
    |
    | Any other keys are passed to the handler as its options. Watches
    | dispatch MonitorWentDown and MonitorRecovered events on changes,
    | show on the dashboard and status, and never affect ping or pass/fail.
    |
    | State is kept in cache_store (the default store when null). Unlike
    | ping.cache_store, it must be shared by every server and support
    | atomic locks, e.g. redis, memcached or database. Each watch runs on
    | one server at a time. Set schedule to false to run the watches with
    | system-status:watch yourself. Force one up or down with
    | system-status:force.
    |
    */

    'watches' => [
        'cache_store' => env('SYSTEM_STATUS_WATCH_CACHE_STORE'),
        'schedule' => true,
        'definitions' => [
            // 'payments' => [
            //     'monitor' => ['type' => 'http', 'url' => 'https://payments.example.com/health'],
            //     'failures' => 3,
            //     'recoveries' => 2,
            //     'handler' => App\Watches\FailOverPayments::class,
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Registers {prefix}/heartbeat, {prefix}/ping, {prefix}/status and
    | {prefix}/dashboard. They sit outside the "web" group so polling
    | never starts a session. status_middleware also applies to the
    | dashboard, which shows the same report.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'monitors',
        'middleware' => [],
        'status_middleware' => [],
        'dashboard_middleware' => [],
    ],

];
