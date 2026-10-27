<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Contracts\Monitor as MonitorContract;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\MonitorFailed;
use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Rating;
use Throwable;

abstract class Monitor implements MonitorContract
{
    protected string $name = 'Monitor';

    /**
     * Config keys that must be present and non-empty.
     *
     * @var list<string>
     */
    protected array $required = [];

    /**
     * Upper response time limits in milliseconds, each mapped to the rating given for finishing
     * under it. A check slower than every limit is rated by the "otherwise" option (default:
     * degraded). Overridden by the "thresholds" option.
     *
     * @var array<int, Rating|int|string>
     */
    protected array $thresholds = [];

    public function __construct(protected array $config = [])
    {
        $missing = array_values(array_filter(
            $this->required,
            fn (string $key) => $this->blank($this->config[$key] ?? null),
        ));

        if ($missing) {
            throw InvalidMonitorConfiguration::missing($this->name(), $missing);
        }

        $this->validateThresholds();
        $this->configure();
    }

    abstract public function check(Probe $probe): void;

    public function name(): string
    {
        return (string) ($this->config['name'] ?? $this->name);
    }

    public function rate(float $duration): Rating
    {
        $thresholds = $this->config['thresholds'] ?? $this->thresholds;

        if (! $thresholds) {
            return Rating::Optimal;
        }

        ksort($thresholds);

        foreach ($thresholds as $limit => $rating) {
            if ($duration < $limit) {
                return Rating::parse($rating);
            }
        }

        return Rating::parse($this->config['otherwise'] ?? Rating::Degraded);
    }

    /**
     * Validate configuration beyond the required keys. Throw InvalidMonitorConfiguration on failure.
     */
    protected function configure(): void
    {
    }

    /**
     * Misconfigured thresholds would otherwise rate every check optimal, hiding slowness.
     */
    protected function validateThresholds(): void
    {
        $thresholds = $this->config['thresholds'] ?? $this->thresholds;

        if (! is_array($thresholds)) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'thresholds must map milliseconds to ratings');
        }

        foreach ($thresholds as $limit => $rating) {
            if (! is_int($limit)) {
                throw InvalidMonitorConfiguration::invalid($this->name(), sprintf('threshold "%s" is not a whole number of milliseconds', $limit));
            }

            if (! $this->isRating($rating)) {
                throw InvalidMonitorConfiguration::invalid($this->name(), sprintf('threshold %dms is not mapped to a valid rating', $limit));
            }
        }

        if (isset($this->config['otherwise']) && ! $this->isRating($this->config['otherwise'])) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'otherwise is not a valid rating');
        }
    }

    protected function isRating(mixed $value): bool
    {
        return ($value instanceof Rating || is_int($value) || is_string($value)) && Rating::tryParse($value) !== null;
    }

    protected function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    protected function fail(string $message, ?Throwable $previous = null): never
    {
        throw new MonitorFailed($message, $previous);
    }
}
