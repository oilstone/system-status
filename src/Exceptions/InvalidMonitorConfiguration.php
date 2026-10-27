<?php

namespace Oilstone\SystemStatus\Exceptions;

use InvalidArgumentException;

/**
 * @phpstan-consistent-constructor
 */
class InvalidMonitorConfiguration extends InvalidArgumentException
{
    /**
     * @param list<string> $keys
     */
    public static function missing(string $monitor, array $keys): static
    {
        return new static(sprintf('%s is missing required configuration: %s', $monitor, implode(', ', $keys)));
    }

    public static function invalid(string $monitor, string $reason): static
    {
        return new static(sprintf('%s is misconfigured: %s', $monitor, $reason));
    }
}
