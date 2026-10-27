<?php

namespace Oilstone\SystemStatus;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use DateTimeInterface;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * @implements IteratorAggregate<int, Result>
 */
final class Report implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param list<Result> $results
     */
    public function __construct(
        private readonly array $results,
        private readonly DateTimeImmutable $checkedAt = new DateTimeImmutable(),
    ) {
    }

    /**
     * @return list<Result>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @return list<Result>
     */
    public function category(string $category): array
    {
        return array_values(array_filter($this->results, fn (Result $result) => $result->category === $category));
    }

    /**
     * @return list<Result>
     */
    public function failures(Rating $failAt = Rating::Failed): array
    {
        return array_values(array_filter($this->results, fn (Result $result) => ! $result->passes($failAt)));
    }

    public function passes(Rating $failAt = Rating::Failed): bool
    {
        return $this->failures($failAt) === [];
    }

    public function rating(): Rating
    {
        return Rating::worst(...array_map(fn (Result $result) => $result->rating, $this->results));
    }

    public function duration(): float
    {
        return array_sum(array_map(fn (Result $result) => $result->duration, $this->results));
    }

    public function checkedAt(): DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function toArray(Rating $failAt = Rating::Failed, bool $exposeErrors = true): array
    {
        $categories = [];

        foreach ($this->results as $result) {
            $categories[$result->category][] = $result->toArray($exposeErrors);
        }

        return [
            'status' => $this->passes($failAt) ? 'pass' : 'fail',
            'rating' => $this->rating()->label(),
            'checked_at' => $this->checkedAt->format(DateTimeInterface::ATOM),
            'duration_ms' => round($this->duration(), 1),
            'categories' => $categories,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function count(): int
    {
        return count($this->results);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }
}
