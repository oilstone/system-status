<?php

namespace Oilstone\SystemStatus\Exceptions;

use Oilstone\SystemStatus\WatchOutcome;
use Throwable;

/**
 * A watch handler threw. The watch's state was already saved, so the outcome stands.
 */
class WatchHandlerFailed extends WatchFailedAfterSave
{
    public function __construct(WatchOutcome $outcome, Throwable $previous)
    {
        parent::__construct(
            $outcome,
            sprintf('The %s watch handler failed: %s', $outcome->watch, $previous->getMessage() !== '' ? $previous->getMessage() : $previous::class),
            $previous,
        );
    }
}
