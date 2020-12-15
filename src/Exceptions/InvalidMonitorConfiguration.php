<?php

namespace Oilstone\SystemStatus\Exceptions;

use Exception;

/**
 * Class InvalidMonitorConfiguration
 * @package Oilstone\SystemStatus\Exceptions
 */
class InvalidMonitorConfiguration extends Exception
{
    /**
     * @var array
     */
    protected array $config;

    /**
     * InvalidMonitorConfiguration constructor.
     * @param $message
     * @param array $config
     */
    public function __construct(string $message, array $config)
    {
        $this->config = $config;

        parent::__construct($message);
    }
}
