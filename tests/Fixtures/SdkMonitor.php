<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Oilstone\SystemStatus\Concerns\MakesHttpRequests;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

/**
 * Exposes the clients MakesHttpRequests builds.
 */
class SdkMonitor extends Monitor
{
    use MakesHttpRequests;

    public function check(Probe $probe): void
    {
    }

    public function monitorClient(): ClientInterface
    {
        return $this->http();
    }

    public function sdkClient(): Client
    {
        return $this->sdkHttpClient();
    }
}
