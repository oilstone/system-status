<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use Oilstone\SystemStatus\Probe;

/**
 * An invokable check that can only be built by a container.
 */
class InjectedCheck
{
    public function __construct(protected Dependency $dependency)
    {
    }

    public function __invoke(Probe $probe): void
    {
        $probe->meta('dependency', $this->dependency->value);
    }
}
