<?php

namespace Oilstone\SystemStatus\Factories;

use Illuminate\Support\Str;
use Oilstone\SystemStatus\Contracts\Monitor as MonitorContract;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\UnknownMonitorClassException;
use Oilstone\SystemStatus\Monitors\BearerToken;
use Oilstone\SystemStatus\Monitors\File;
use Oilstone\SystemStatus\Monitors\Http;
use Oilstone\SystemStatus\Monitors\MySql;
use Oilstone\SystemStatus\Monitors\Redis;

/**
 * Class Monitor
 * @package Oilstone\SystemStatus\Factories
 */
class Monitor
{
    /**
     * @param mixed $config
     * @return MonitorContract
     * @throws UnknownMonitorClassException
     */
    public static function make($config): MonitorContract
    {
        if ($config instanceof MonitorContract) {
            return $config;
        }

        if (is_callable($config)) {
            return $config();
        }

        if (is_string($config)) {
            return new $config();
        }

        if (is_array($config) && isset($config['type'])) {
            $monitorType = lcfirst(Str::camel(strtolower($config['type'])));

            return static::{$monitorType}($config);
        }

        throw new UnknownMonitorClassException($config);
    }

    /**
     * @param array $config
     * @return Http
     * @throws InvalidMonitorConfiguration
     */
    public static function http(array $config): Http
    {
        return new Http($config);
    }

    /**
     * @param array $config
     * @return MySql
     * @throws InvalidMonitorConfiguration
     */
    public static function mysql(array $config): MySql
    {
        return new MySql($config);
    }

    /**
     * @param array $config
     * @return File
     * @throws InvalidMonitorConfiguration
     */
    public static function file(array $config): File
    {
        return new File($config);
    }

    /**
     * @param array $config
     * @return Redis
     * @throws InvalidMonitorConfiguration
     */
    public static function redis(array $config): Redis
    {
        return new Redis($config);
    }

    /**
     * @param array $config
     * @return BearerToken
     * @throws InvalidMonitorConfiguration
     */
    public static function bearerToken(array $config): BearerToken
    {
        return new BearerToken($config);
    }
}
