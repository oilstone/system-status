<?php

namespace Oilstone\SystemStatus;

use DateTimeImmutable;
use Oilstone\SystemStatus\Contracts\WatchHandler;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Throwable;

/**
 * Runs watches: checks the monitor (or applies an override), counts consecutive failures and
 * passes, moves the watch down or up once a threshold is reached, saves that, then tells the
 * handler the outcome.
 */
class Watcher
{
    public function __construct(protected SystemStatus $status, protected WatchStore $store)
    {
    }

    /**
     * @throws Throwable When the monitor or handler can't be built, or the state can't be saved; nothing changes
     * @throws WatchHandlerFailed When the handler throws; the state has already been saved
     */
    public function run(Watch $watch): WatchOutcome
    {
        // A misconfigured watch must never look like an outage, so nothing changes until both can be built
        $monitor = $this->status->make($watch->monitor);
        $handler = $watch->handler === null ? null : $this->makeHandler($watch->handler);

        $checkedAt = new DateTimeImmutable();
        $current = $this->store->get($watch->name);
        $forced = $current->forced;
        $result = null;

        if ($forced === null) {
            $result = Probe::run($monitor, 'watches');
            $failed = ! $result->passes($watch->failAt);
        } else {
            $failed = $forced === WatchState::Down;
        }

        $failures = $failed ? $current->failures + 1 : 0;
        $passes = $failed ? 0 : $current->passes + 1;

        $state = match (true) {
            $forced !== null => $forced,
            $failed && $failures >= $watch->failures => WatchState::Down,
            ! $failed && $passes >= $watch->recoveries => WatchState::Up,
            default => $current->state,
        };

        $this->store->save($watch->name, $state, $failures, $passes, $checkedAt, $result);

        $outcome = new WatchOutcome($watch->name, $monitor, $state, $current->state, $forced, $result, $failures, $passes, $checkedAt);

        try {
            $handler?->handle($outcome, $watch->options);
        } catch (Throwable $e) {
            throw new WatchHandlerFailed($outcome, $e);
        }

        return $outcome;
    }

    public function store(): WatchStore
    {
        return $this->store;
    }

    /**
     * @param class-string<WatchHandler> $class
     */
    protected function makeHandler(string $class): WatchHandler
    {
        return new $class();
    }
}
