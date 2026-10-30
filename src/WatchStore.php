<?php

namespace Oilstone\SystemStatus;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Keeps each watch's state, counters and override in a PSR-16 cache. Every process that runs or
 * forces a watch must share the cache, or each will track its own state.
 */
class WatchStore
{
    public function __construct(protected CacheInterface $cache, protected string $prefix = 'system-status.watch.')
    {
    }

    public function get(string $watch): WatchStatus
    {
        $stored = $this->cache->get($this->key($watch, 'state'));
        $override = $this->cache->get($this->key($watch, 'override'));
        $failed = $this->cache->get($this->key($watch, 'failed'));
        $failedAt = is_array($failed) ? $this->time($failed['at'] ?? null) : null;

        $state = is_array($stored) ? WatchState::tryFrom((string) ($stored['state'] ?? '')) : null;
        $forced = is_array($override) ? WatchState::tryFrom((string) ($override['state'] ?? '')) : null;

        return new WatchStatus(
            $state ?? WatchState::Up,
            $state === null ? 0 : max(0, (int) ($stored['failures'] ?? 0)),
            $state === null ? 0 : max(0, (int) ($stored['passes'] ?? 0)),
            $state === null ? null : $this->time($stored['checked_at'] ?? null),
            $forced,
            $forced === null ? null : $this->time($override['expires_at'] ?? null),
            $state === null ? null : Rating::tryParse(is_string($stored['rating'] ?? null) ? $stored['rating'] : null),
            $state === null || ! is_numeric($stored['duration_ms'] ?? null) ? null : (float) $stored['duration_ms'],
            $state === null || ! is_string($stored['error'] ?? null) ? null : $stored['error'],
            $failedAt,
            $failedAt === null ? null : (string) ($failed['error'] ?? ''),
        );
    }

    /**
     * @param Result|null $result The check's result, kept to show why it failed; null when forced
     *
     * @throws RuntimeException When the cache refuses the write
     */
    public function save(string $watch, WatchState $state, int $failures, int $passes, DateTimeInterface $checkedAt, ?Result $result = null): void
    {
        $saved = $this->cache->set($this->key($watch, 'state'), [
            'state' => $state->value,
            'failures' => $failures,
            'passes' => $passes,
            'checked_at' => $checkedAt->getTimestamp(),
            'rating' => $result?->rating->label(),
            'duration_ms' => $result === null ? null : round($result->duration, 1),
            'error' => $result?->errorMessage(),
        ]);

        if (! $saved) {
            throw new RuntimeException(sprintf('Unable to save the state of the %s watch', $watch));
        }

        // This run got as far as checking, so any earlier failed run no longer explains a stale state
        $this->cache->delete($this->key($watch, 'failed'));
    }

    /**
     * Record a run that stopped before it could check, such as one of a misconfigured watch, without changing the
     * watch's state, so whoever reads it can see why it hasn't been checked. Cleared by the next save().
     */
    public function fail(string $watch, string $error, DateTimeInterface $at): void
    {
        $this->cache->set($this->key($watch, 'failed'), ['at' => $at->getTimestamp(), 'error' => $error]);
    }

    /**
     * Use this state instead of checking, from the watch's next run until cleared.
     *
     * @param int|null $seconds Clear the override after this long; it lasts until cleared when null
     *
     * @throws RuntimeException When the cache refuses the write
     */
    public function force(string $watch, WatchState $state, ?int $seconds = null): void
    {
        if ($seconds !== null && $seconds < 1) {
            throw new InvalidArgumentException('An override must last at least a second');
        }

        // The TTL clears the override; the expiry is only kept to show when that will happen
        $saved = $this->cache->set($this->key($watch, 'override'), [
            'state' => $state->value,
            'expires_at' => $seconds === null ? null : time() + $seconds,
        ], $seconds);

        if (! $saved) {
            throw new RuntimeException(sprintf('Unable to force the %s watch %s', $watch, $state->value));
        }
    }

    public function clear(string $watch): void
    {
        $this->cache->delete($this->key($watch, 'override'));
    }

    protected function key(string $watch, string $record): string
    {
        return $this->prefix . $watch . '.' . $record;
    }

    protected function time(mixed $timestamp): ?DateTimeImmutable
    {
        if (! is_int($timestamp)) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
