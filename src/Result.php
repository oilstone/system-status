<?php

namespace Oilstone\SystemStatus;

use Throwable;

final class Result
{
    /**
     * @param float $duration Milliseconds spent in the monitor's check
     * @param list<string> $notes
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $category,
        public readonly string $name,
        public readonly Rating $rating,
        public readonly float $duration,
        public readonly ?Throwable $error = null,
        public readonly array $notes = [],
        public readonly array $meta = [],
    ) {
    }

    public function failed(): bool
    {
        return $this->rating === Rating::Failed;
    }

    public function passes(Rating $failAt = Rating::Failed): bool
    {
        return ! $this->rating->isAtLeast($failAt);
    }

    public function errorMessage(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        return $this->error->getMessage() !== '' ? $this->error->getMessage() : $this->error::class;
    }

    /**
     * @param bool $exposeErrors Error messages can contain hostnames, usernames and paths
     */
    public function toArray(bool $exposeErrors = true): array
    {
        return array_filter([
            'name' => $this->name,
            'rating' => $this->rating->label(),
            'duration_ms' => round($this->duration, 1),
            'error' => $this->error === null ? null : ($exposeErrors ? $this->errorMessage() : 'Check failed'),
            'notes' => $this->notes ?: null,
            'meta' => $this->meta ?: null,
        ], fn (mixed $value) => $value !== null);
    }
}
