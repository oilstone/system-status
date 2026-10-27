<?php

namespace Oilstone\SystemStatus\Exceptions;

use RuntimeException;
use Throwable;

class MonitorFailed extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, (int) $previous?->getCode(), $previous);
    }
}
