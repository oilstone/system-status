<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use Oilstone\SystemStatus\Contracts\WatchHandler;
use Oilstone\SystemStatus\WatchOutcome;
use RuntimeException;

/**
 * Records every call. Throws when the "throw" option is set.
 */
class RecordingWatchHandler implements WatchHandler
{
    /** @var list<array{outcome: WatchOutcome, options: array}> */
    public static array $calls = [];

    public function handle(WatchOutcome $outcome, array $options): void
    {
        static::$calls[] = ['outcome' => $outcome, 'options' => $options];

        if ($options['throw'] ?? false) {
            throw new RuntimeException('Failover API refused the request');
        }
    }
}
