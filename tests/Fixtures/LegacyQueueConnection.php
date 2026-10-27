<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

/**
 * A queue connection as drivers report it before Laravel 11, with only a total size.
 */
class LegacyQueueConnection
{
    public function size($queue = null): int
    {
        return 7;
    }
}
