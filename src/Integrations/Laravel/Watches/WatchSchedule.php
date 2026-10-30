<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Watches;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * When the scheduler runs a watch, and whether a scheduled run has been missed.
 */
final class WatchSchedule
{
    /**
     * @param string $cron The expression with any alias like @daily expanded
     * @param string $timezone The timezone the scheduler reads the expression in
     * @param DateTimeImmutable|null $overdueSince The scheduled run that hasn't happened; null when none is late
     */
    public function __construct(
        public readonly string $cron,
        public readonly string $timezone,
        public readonly DateTimeImmutable $nextRunAt,
        public readonly ?DateTimeImmutable $overdueSince = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'cron' => $this->cron,
            'timezone' => $this->timezone,
            'next_run_at' => $this->nextRunAt->format(DateTimeInterface::ATOM),
            'overdue_since' => $this->overdueSince?->format(DateTimeInterface::ATOM),
        ];
    }
}
