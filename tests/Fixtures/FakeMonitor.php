<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

/**
 * Options:
 * - check: closure given the probe, run as the check
 */
class FakeMonitor extends Monitor
{
    protected string $name = 'Fake';

    public function check(Probe $probe): void
    {
        if (isset($this->config['check'])) {
            ($this->config['check'])($probe);
        }
    }
}
