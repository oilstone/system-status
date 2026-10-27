<?php

namespace Oilstone\SystemStatus\Integrations\Laravel;

use Illuminate\Contracts\Container\Container;
use Oilstone\SystemStatus\Contracts\Monitor;
use Oilstone\SystemStatus\Exceptions\UnknownMonitorType;
use Oilstone\SystemStatus\MonitorFactory;

/**
 * Builds monitor classes through the container so their constructors can inject dependencies
 * alongside the options, which are passed as the "config" parameter.
 */
class ContainerMonitorFactory extends MonitorFactory
{
    public function __construct(protected Container $container)
    {
    }

    protected function instantiate(string $class, array $options): Monitor
    {
        if (! is_subclass_of($class, Monitor::class)) {
            throw UnknownMonitorType::forClass($class);
        }

        return $this->container->make($class, ['config' => $options]);
    }
}
