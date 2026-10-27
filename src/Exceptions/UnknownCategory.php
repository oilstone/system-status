<?php

namespace Oilstone\SystemStatus\Exceptions;

use InvalidArgumentException;

class UnknownCategory extends InvalidArgumentException
{
    /**
     * @param list<string> $categories
     */
    public static function make(array $categories): static
    {
        return new static(sprintf('Unknown monitor categories: %s', implode(', ', $categories)));
    }

    public static function none(): static
    {
        return new static('No monitor categories were requested');
    }
}
