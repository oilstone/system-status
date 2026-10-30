<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Watches;

use Oilstone\SystemStatus\Exceptions\WatchFailedAfterSave;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Oilstone\SystemStatus\WatchOutcome;
use Throwable;

/**
 * A listener for a watch's MonitorWentDown or MonitorRecovered event threw. The events follow the saved state, so
 * the outcome stands.
 */
class WatchEventFailed extends WatchFailedAfterSave
{
    /**
     * @param string $event The event's class
     * @param WatchHandlerFailed|null $handlerFailure The handler's failure, when it had already thrown on this run
     */
    public function __construct(WatchOutcome $outcome, string $event, Throwable $previous, public readonly ?WatchHandlerFailed $handlerFailure = null)
    {
        parent::__construct(
            $outcome,
            sprintf(
                'A %s listener for the %s watch failed: %s%s',
                class_basename($event),
                $outcome->watch,
                $previous->getMessage() !== '' ? $previous->getMessage() : $previous::class,
                $handlerFailure === null ? '' : ' (after the handler failed too)',
            ),
            $previous,
        );
    }
}
