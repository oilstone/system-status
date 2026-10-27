<?php

namespace Oilstone\SystemStatus;

use ValueError;

/**
 * Lower values are healthier, so ratings can be compared numerically.
 */
enum Rating: int
{
    case Optimal = 100;
    case Acceptable = 200;
    case Degraded = 300;
    case Failed = 400;

    /**
     * Accepts a case, its value (100) or its name in any case ("degraded").
     */
    public static function parse(self|int|string $rating): self
    {
        if ($rating instanceof self) {
            return $rating;
        }

        return self::tryParse($rating) ?? throw new ValueError(sprintf('"%s" is not a valid rating', $rating));
    }

    public static function tryParse(self|int|string|null $rating): ?self
    {
        if ($rating === null || $rating instanceof self) {
            return $rating;
        }

        if (is_int($rating) || ctype_digit($rating)) {
            return self::tryFrom((int) $rating);
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->name, $rating) === 0) {
                return $case;
            }
        }

        return null;
    }

    public static function worst(self ...$ratings): self
    {
        return array_reduce(
            $ratings,
            fn (self $worst, self $rating) => $rating->value > $worst->value ? $rating : $worst,
            self::Optimal,
        );
    }

    public function isAtLeast(self $rating): bool
    {
        return $this->value >= $rating->value;
    }

    public function label(): string
    {
        return strtolower($this->name);
    }
}
