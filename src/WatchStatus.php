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
     * @param Rating|null $rating The last check's rating; null before the first check or when the last run was forced
     * @param float|null $duration Milliseconds the last check took
     * @param string|null $error Why the last check failed, when it threw
     */
    public function __construct(
        public readonly WatchState $state = WatchState::Up,
        public readonly int $failures = 0,
        public readonly int $passes = 0,
        public readonly ?DateTimeImmutable $checkedAt = null,
        public readonly ?WatchState $forced = null,
        public readonly ?DateTimeImmutable $forcedUntil = null,
        public readonly ?Rating $rating = null,
        public readonly ?float $duration = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @param bool $exposeErrors Error messages can contain hostnames, usernames and paths
     */
    public function toArray(bool $exposeErrors = true): array
    {
        return [
            'state' => $this->state->value,
            'failures' => $this->failures,
            'passes' => $this->passes,
            'forced' => $this->forced?->value,
            'forced_until' => $this->forcedUntil?->format(DateTimeInterface::ATOM),
            'checked_at' => $this->checkedAt?->format(DateTimeInterface::ATOM),
            'last_check' => $this->rating === null ? null : array_filter([
                'rating' => $this->rating->label(),
                'duration_ms' => $this->duration,
                'error' => $this->error === null ? null : ($exposeErrors ? $this->error : 'Check failed'),
            ], fn (mixed $value) => $value !== null),
        ];
    }
}
