<?php

namespace Oilstone\SystemStatus;

use DateTimeImmutable;
use Oilstone\SystemStatus\Contracts\Monitor;

/**
 * What one run of a watch found, and the state it left the watch in.
 */
final class WatchOutcome
{
    public readonly bool $changed;

    /**
     * @param Monitor $monitor The monitor built for this run, for handlers that need more from it
     * @param WatchState|null $forced The override applied instead of a check
     * @param Result|null $result The check's result; null when forced
     * @param int $failures Consecutive failed runs, including this one
     * @param int $passes Consecutive passing runs, including this one
     */
    public function __construct(
        public readonly string $watch,
        public readonly Monitor $monitor,
        public readonly WatchState $state,
        public readonly WatchState $previous,
        public readonly ?WatchState $forced,
        public readonly ?Result $result,
        public readonly int $failures,
        public readonly int $passes,
        public readonly DateTimeImmutable $checkedAt,
    ) {
        $this->changed = $state !== $previous;
    }

    public function isDown(): bool
    {
        return $this->state === WatchState::Down;
    }

    public function wentDown(): bool
    {
        return $this->changed && $this->isDown();
    }

    public function recovered(): bool
    {
        return $this->changed && ! $this->isDown();
    }
}
