<?php

namespace Oilstone\SystemStatus\Exceptions;

use Exception;
use Throwable;

/**
 * Class MonitorFailedException
 * @package Oilstone\SystemStatus\Exceptions
 */
class MonitorFailedException extends Exception
{
    /**
     * MonitorFailedException constructor.
     * @param string $message
     * @param Throwable|null $previous
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, $previous ? $previous->getCode() : 0, $previous);
    }
}
