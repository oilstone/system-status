<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

class MonitorWithDependency extends Monitor
{
    protected string $name = 'With dependency';

    public function __construct(array $config, protected Dependency $dependency)
    {
        parent::__construct($config);
    }

    public function check(Probe $probe): void
    {
        $probe->meta('dependency', $this->dependency->value)->meta('option', $this->config['option'] ?? null);
    }
}
