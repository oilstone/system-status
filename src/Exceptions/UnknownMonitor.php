<?php

namespace Oilstone\SystemStatus\Exceptions;

use InvalidArgumentException;

/**
 * @phpstan-consistent-constructor
 */
class UnknownMonitor extends InvalidArgumentException
{
    public static function make(string $id): static
    {
        return new static(sprintf('No monitor has the id "%s"', $id));
    }
}
