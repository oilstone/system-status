<?php

namespace Oilstone\SystemStatus\Exceptions;

use Oilstone\SystemStatus\WatchOutcome;
use RuntimeException;
use Throwable;

/**
 * Something failed after a watch's run had checked and saved its state, so the outcome stands and the run did
 * happen; only what followed it, such as telling a handler, went wrong.
 */
abstract class WatchFailedAfterSave extends RuntimeException
{
    public function __construct(public readonly WatchOutcome $outcome, string $message, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
