<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Watches;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Integrations\Laravel\Console\WatchCommand;
use Oilstone\SystemStatus\SystemStatus;
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
     * How late a scheduled run may start before the watch is overdue: as long as the previous run may hold its lock.
     */
    public const OVERDUE_AFTER_SECONDS = WatchCommand::LOCK_SECONDS;

    protected string $timezone;

    /**
     * @param array<string, array> $definitions
     * @param Closure(): WatchStore $store Resolved only when statuses are read
     * @param bool $scheduled Whether the watches are added to Laravel's scheduler
     * @param DateTimeZone|string|null $timezone The scheduler's timezone; the default timezone when null
     * @param bool $overdueWarnings Whether to report missed runs; off where schedule:run isn't running, e.g. locally
     */
    public function __construct(
        protected array $definitions,
        protected Closure $store,
        protected bool $scheduled = true,
        DateTimeZone|string|null $timezone = null,
        protected bool $overdueWarnings = true,
    ) {
        $this->timezone = $timezone instanceof DateTimeZone ? $timezone->getName() : ($timezone ?? date_default_timezone_get());
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
     * The id of the status monitor the watch checks, when its monitor names one rather than defining its own.
     */
    public function monitorId(string $name, SystemStatus $status): ?string
    {
        try {
            $monitor = $this->get($name)->monitor;
        } catch (InvalidArgumentException) {
            return null;
        }

        return is_string($monitor) && $status->hasMonitor($monitor) ? $monitor : null;
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
     * When the scheduler runs the watch next and, given its status, any scheduled run it has missed.
     *
     * @return WatchSchedule|null Null when the watches aren't scheduled
     *
     * @throws InvalidMonitorConfiguration When the expression is invalid
     */
    public function schedule(string $name, ?WatchStatus $status = null, ?DateTimeInterface $now = null): ?WatchSchedule
    {
        if (! $this->scheduled) {
            return null;
        }

        $cron = new CronExpression($this->cron($name));
        $now = DateTimeImmutable::createFromInterface($now ?? Date::now());
        // The first run due after the last one started; a run that is still going, or holding up the next, isn't late
        $missed = $status?->checkedAt === null || ! $this->overdueWarnings
            ? null
            : DateTimeImmutable::createFromMutable($cron->getNextRunDate($status->checkedAt, 0, false, $this->timezone));

        return new WatchSchedule(
            (string) $cron->getExpression(),
            $this->timezone,
            DateTimeImmutable::createFromMutable($cron->getNextRunDate($now, 0, false, $this->timezone)),
            $missed !== null && $now->getTimestamp() - $missed->getTimestamp() > static::OVERDUE_AFTER_SECONDS ? $missed : null,
        );
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
