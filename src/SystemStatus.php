<?php

namespace Oilstone\SystemStatus;

use Oilstone\SystemStatus\Exceptions\UnknownMonitorClassException;
use Oilstone\SystemStatus\Factories\Monitor;

/**
 * Class SystemStatus
 * @package Oilstone\SystemStatus
 */
class SystemStatus
{
    /**
     * @var array
     */
    protected static array $monitors = [];

    /**
     * @param mixed $monitor
     * @param string $category
     */
    public static function addMonitor($monitor, string $category = 'default'): void
    {
        static::$monitors[$category][] = $monitor;
    }

    /**
     * @param array $monitors
     * @param bool $replace
     */
    public static function addMonitors(array $monitors, bool $replace = false): void
    {
        static::$monitors = $replace ? $monitors : array_merge(static::$monitors, $monitors);
    }

    /**
     * @param string[]|string|null $categories
     * @return bool
     * @throws UnknownMonitorClassException
     */
    public static function isSystemOkay($categories = null): bool
    {
        foreach (static::fetchMonitors($categories) as $monitor) {
            if ($monitor->status() !== StatusCode::SYSTEM_OKAY) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param null $categories
     * @return array
     * @throws UnknownMonitorClassException
     */
    protected static function fetchMonitors($categories = null): array
    {
        $monitors = [];

        if ($categories === null) {
            $categories = array_keys(static::$monitors);
        }

        foreach ((array)$categories as $category) {
            foreach (static::$monitors[$category] ?? [] as $index => $monitor) {
                static::$monitors[$category][$index] = $monitors[] = Monitor::make($monitor);
            }
        }

        return $monitors;
    }

    /**
     * @param null $categories
     * @return int
     * @throws UnknownMonitorClassException
     */
    public static function averageScore($categories = null): int
    {
        $scores = static::scores($categories);

        return round(ceil(($scores ? array_sum($scores) / count($scores) : 0) / 100) * 100);
    }

    /**
     * @param null $categories
     * @return array
     * @throws UnknownMonitorClassException
     */
    public static function scores($categories = null): array
    {
        $scores = [];

        foreach (static::fetchMonitors($categories) as $monitor) {
            /** @var Contracts\Monitor $monitor */
            $scores[$monitor->alias()] = $monitor->score();
        }

        return $scores;
    }

    /**
     * @param null $categories
     * @return array
     * @throws UnknownMonitorClassException
     */
    public static function errors($categories = null): array
    {
        $errors = [];

        foreach (static::fetchMonitors($categories) as $monitor) {
            /** @var Contracts\Monitor $monitor */
            $errors[$monitor->alias()] = (string)($monitor->error() ?? '');
        }

        return array_values(array_filter($errors));
    }
}
