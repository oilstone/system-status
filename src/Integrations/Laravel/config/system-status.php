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
    | Routes
    |--------------------------------------------------------------------------
    |
    | Registers {prefix}/heartbeat, {prefix}/ping and {prefix}/status. They
    | sit outside the "web" group so polling never starts a session.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'monitors',
        'middleware' => [],
        'status_middleware' => [],
    ],

];
