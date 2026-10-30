<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Watches;

use Closure;
use Cron\CronExpression;
use InvalidArgumentException;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Watch;
use Oilstone\SystemStatus\WatchStatus;
use Oilstone\SystemStatus\WatchStore;
use Throwable;

/**
 * The watch definitions from config, keyed by name.
 */
class WatchRegistry
{
    public const DEFAULT_CRON = '* * * * *';

    /**
     * @param array<string, array> $definitions
     * @param Closure(): WatchStore $store Resolved only when statuses are read
     */
    public function __construct(protected array $definitions, protected Closure $store)
    {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->definitions));
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * @throws InvalidArgumentException When the watch isn't defined or is misconfigured
     */
    public function get(string $name): Watch
    {
        $definition = $this->definitions[$name] ?? throw new InvalidArgumentException(sprintf('No watch named "%s" is defined', $name));

        unset($definition['cron']);

        return Watch::fromArray($name, (array) $definition);
    }

    /**
     * @throws InvalidMonitorConfiguration When the expression is invalid, which the scheduler can't recover from
     */
    public function cron(string $name): string
    {
        $cron = $this->definitions[$name]['cron'] ?? self::DEFAULT_CRON;

        if (! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
            throw InvalidMonitorConfiguration::invalid(sprintf('The %s watch', $name), 'cron must be a valid cron expression');
        }

        return $cron;
    }

    /**
     * Each watch's stored status, without running anything; null where it couldn't be read.
     *
     * @return array<string, WatchStatus|null>
     */
    public function statuses(): array
    {
        if ($this->definitions === []) {
            return [];
        }

        try {
            $store = ($this->store)();
        } catch (Throwable) {
            $store = null;
        }

        $statuses = [];

        foreach ($this->names() as $name) {
            try {
                $statuses[$name] = $store?->get($name);
            } catch (Throwable) {
                $statuses[$name] = null;
            }
        }

        return $statuses;
    }
}
