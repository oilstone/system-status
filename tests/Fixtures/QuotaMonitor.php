<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use Oilstone\SystemStatus\Concerns\RatesUsage;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

/**
 * Options:
 * - used, limit: the quota usage to rate
 * - degrade_usage, max_usage (default 80 and 95)
 */
class QuotaMonitor extends Monitor
{
    use RatesUsage;

    protected string $name = 'Quota';

    public ?float $percentage = null;

    public function check(Probe $probe): void
    {
        $this->percentage = $this->rateUsage($probe, 'Daily API', (int) $this->config['used'], (int) $this->config['limit']);
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
