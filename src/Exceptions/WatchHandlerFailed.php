<?php

namespace Oilstone\SystemStatus\Exceptions;

use Oilstone\SystemStatus\WatchOutcome;
use RuntimeException;
use Throwable;

/**
 * A watch handler threw. The watch's state was already saved, so the outcome stands.
 */
class WatchHandlerFailed extends RuntimeException
{
    public function __construct(public readonly WatchOutcome $outcome, Throwable $previous)
    {
        parent::__construct(
            sprintf('The %s watch handler failed: %s', $outcome->watch, $previous->getMessage() !== '' ? $previous->getMessage() : $previous::class),
            0,
            $previous,
        );
    }
}
