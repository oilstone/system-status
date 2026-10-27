<?php

namespace Oilstone\SystemStatus\Exceptions;

use InvalidArgumentException;
use Oilstone\SystemStatus\Contracts\Monitor;

/**
 * @phpstan-consistent-constructor
 */
class UnknownMonitorType extends InvalidArgumentException
{
    public static function forType(string $type): static
    {
        return new static(sprintf('No monitor type named "%s" has been registered', $type));
    }

    public static function forClass(string $class): static
    {
        return new static(sprintf('"%s" does not implement %s', $class, Monitor::class));
    }

    public static function forDefinition(mixed $definition): static
    {
        return new static(sprintf(
            'Unable to build a monitor from a definition of type %s; expected a monitor, closure, class name or array with a "type" or "class" key',
            get_debug_type($definition),
        ));
    }
}
