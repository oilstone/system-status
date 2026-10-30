<?php

namespace Oilstone\SystemStatus\Contracts;

use Oilstone\SystemStatus\WatchOutcome;

interface WatchHandler
{
    /**
     * Called after every run of a watch, not only when its state changes, so a handler can
     * reconcile idempotently and retry anything that failed on the previous run. Throwing does
     * not roll back the watch's state.
     *
     * @param array $options The watch definition's keys that the watch itself doesn't use
     */
    public function handle(WatchOutcome $outcome, array $options): void;
}
