<?php

namespace Oilstone\SystemStatus\Exceptions;

use Exception;

/**
 * Class UnknownMonitorClassException
 * @package Oilstone\SystemStatus\Exceptions
 */
class UnknownMonitorClassException extends Exception
{
    /**
     * @var mixed
     */
    protected $config;

    /**
     * UnknownMonitorClassException constructor.
     * @param mixed $config
     */
    public function __construct($config)
    {
        $this->config = $config;

        parent::__construct('Failed to determine the type of monitor from the provided configuration');
    }
}
