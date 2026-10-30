<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Events;

use Oilstone\SystemStatus\WatchOutcome;

/**
 * A watch went from up to down on this run.
 */
class MonitorWentDown
{
    public function __construct(public readonly WatchOutcome $outcome)
    {
    }
}
