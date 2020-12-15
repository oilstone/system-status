<?php

namespace Oilstone\SystemStatus;

use ReflectionClass;

/**
 * Class StatusCode
 * @package Oilstone\SystemStatus
 */
class StatusCode
{
    /**
     * Status codes
     */
    const SYSTEM_OKAY = 1;
    const SYSTEM_OFFLINE = 2;

    /**
     * Scores
     */
    const ACCEPTABLE = 100;
    const SATISFACTORY = 200;
    const UNACCEPTABLE = 300;
    const UNAVAILABLE = 400;

    /**
     * @param int $statusCode
     * @return string|null
     */
    public static function toString(int $statusCode): ?string
    {
        $statusCodes = (new ReflectionClass (static::class))->getConstants();
        $statusName = null;

        foreach ($statusCodes as $name => $value) {
            if ($statusCode >= $value) {
                $statusName = $name;
            }
        }

        return $statusName;
    }
}
