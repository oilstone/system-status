<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

/**
 * A queue connection that reports pending counts and job ages.
 */
class FakeQueueConnection
{
    public function __construct(
        protected int $size = 0,
        protected int $pending = 0,
        protected ?int $oldest = null,
    ) {
    }

    public function size($queue = null): int
    {
        return $this->size;
    }

    public function pendingSize($queue = null): int
    {
        return $this->pending;
    }

    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return $this->oldest;
    }
}
