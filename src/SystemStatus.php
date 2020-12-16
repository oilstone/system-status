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
        foreach (static::fetchMonitors($categories) as $next) {
            if ($next['monitor']->status() !== StatusCode::SYSTEM_OKAY) {
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
                $monitors[] = [
                    'category' => $category,
                    'monitor' => static::$monitors[$category][$index] = Monitor::make($monitor),
                ];
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
        $scores = [];

        foreach (static::fetchMonitors($categories) as $next) {
            $scores[] = $next['monitor']->score();
        }

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

        foreach (static::fetchMonitors($categories) as $next) {
            $scores[$next['category']][$next['monitor']->alias()] = [
                'score' => $next['monitor']->score(),
                'rating' => StatusCode::toString($next['monitor']->score()),
            ];
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

        foreach (static::fetchMonitors($categories) as $next) {
            if ($next['monitor']->error()) {
                $error = $next['monitor']->error()->getMessage();

                if (!$error) {
                    $error = (string)$next['monitor']->error();
                }

                $errors[$next['category']][$next['monitor']->alias()] = $error;
            }
        }

        return $errors;
    }
}
