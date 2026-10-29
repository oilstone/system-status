<?php

namespace Oilstone\SystemStatus;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A watch's stored state, as left by its last run, and any override waiting for the next.
 */
final class WatchStatus
{
    /**
     * @param DateTimeImmutable|null $checkedAt Null until the watch first runs
     * @param DateTimeImmutable|null $forcedUntil When the override clears itself; null when it lasts until cleared
     */
    public function __construct(
        public readonly WatchState $state = WatchState::Up,
        public readonly int $failures = 0,
        public readonly int $passes = 0,
        public readonly ?DateTimeImmutable $checkedAt = null,
        public readonly ?WatchState $forced = null,
        public readonly ?DateTimeImmutable $forcedUntil = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'failures' => $this->failures,
            'passes' => $this->passes,
            'forced' => $this->forced?->value,
            'forced_until' => $this->forcedUntil?->format(DateTimeInterface::ATOM),
            'checked_at' => $this->checkedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
