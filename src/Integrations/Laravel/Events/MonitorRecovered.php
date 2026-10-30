<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Events;

use Oilstone\SystemStatus\WatchOutcome;

/**
 * A watch went from down to up on this run.
 */
class MonitorRecovered
{
    public function __construct(public readonly WatchOutcome $outcome)
    {
    }
}
