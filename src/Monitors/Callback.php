<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Probe;
use Psr\Container\ContainerInterface;

/**
 * Options:
 * - callback: callable or invokable class name, called with (Probe $probe, array $config).
 *   Fails if it throws or returns false. Class names are resolved through the container when
 *   one is given, as it is in Laravel, so they can inject dependencies.
 */
class Callback extends Monitor
{
    protected string $name = 'Callback';

    protected array $required = ['callback'];

    public function __construct(array $config = [], protected ?ContainerInterface $container = null)
    {
        parent::__construct($config);
    }

    public function check(Probe $probe): void
    {
        if (($this->resolveCallback())($probe, $this->config) === false) {
            $this->fail($this->name() . ' returned false');
        }
    }

    protected function resolveCallback(): callable
    {
        $callback = $this->config['callback'];

        if (is_string($callback) && ! is_callable($callback) && class_exists($callback)) {
            $callback = $this->container?->get($callback) ?? new $callback();
        }

        if (! is_callable($callback)) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'the callback is not callable');
        }

        return $callback;
    }
}
