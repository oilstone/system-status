<?php

namespace Oilstone\SystemStatus;

use Oilstone\SystemStatus\Contracts\WatchHandler;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;

/**
 * A scheduled check of one monitor that tracks whether it is up or down.
 *
 * Options:
 * - monitor: a monitor definition array, or a type or monitor class name (required)
 * - failures: consecutive failed checks before the watch is down (default 1)
 * - recoveries: consecutive passing checks before a down watch is up again (default 1)
 * - fail_at: the rating that counts as failed (default failed)
 * - handler: a WatchHandler class told the outcome of every run
 *
 * Any other keys are the handler's options.
 */
final class Watch
{
    private const RESERVED = ['monitor', 'failures', 'recoveries', 'fail_at', 'handler'];

    /**
     * @param array|string $monitor See MonitorFactory::make()
     * @param class-string<WatchHandler>|null $handler
     */
    private function __construct(
        public readonly string $name,
        public readonly array|string $monitor,
        public readonly int $failures,
        public readonly int $recoveries,
        public readonly Rating $failAt,
        public readonly ?string $handler,
        public readonly array $options,
    ) {
    }

    /**
     * @throws InvalidMonitorConfiguration
     */
    public static function fromArray(string $name, array $definition): self
    {
        // Names become cache keys and command arguments, so keep them to characters that are safe in both
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw InvalidMonitorConfiguration::invalid(
                sprintf('Watch "%s"', $name),
                'names may only contain letters, numbers, dots, dashes and underscores',
            );
        }

        $label = sprintf('The %s watch', $name);
        $monitor = $definition['monitor'] ?? null;
        $handler = $definition['handler'] ?? null;
        $failAt = $definition['fail_at'] ?? Rating::Failed;

        if (! (is_array($monitor) && $monitor !== []) && ! (is_string($monitor) && $monitor !== '')) {
            throw InvalidMonitorConfiguration::invalid($label, 'monitor must be a monitor definition array, or a type or class name');
        }

        if (! ($failAt instanceof Rating || is_int($failAt) || is_string($failAt)) || Rating::tryParse($failAt) === null) {
            throw InvalidMonitorConfiguration::invalid($label, 'fail_at must be one of optimal, acceptable, degraded or failed');
        }

        if ($handler !== null && ! (is_string($handler) && is_a($handler, WatchHandler::class, true))) {
            throw InvalidMonitorConfiguration::invalid($label, 'handler must be the name of a class implementing ' . WatchHandler::class);
        }

        return new self(
            $name,
            $monitor,
            self::threshold($label, $definition, 'failures'),
            self::threshold($label, $definition, 'recoveries'),
            Rating::parse($failAt),
            $handler,
            array_diff_key($definition, array_flip(self::RESERVED)),
        );
    }

    private static function threshold(string $label, array $definition, string $key): int
    {
        $value = $definition[$key] ?? 1;
        $threshold = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if ($threshold === false) {
            throw InvalidMonitorConfiguration::invalid($label, sprintf('%s must be a whole number of at least 1', $key));
        }

        return $threshold;
    }
}
