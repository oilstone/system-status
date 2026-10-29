<?php

namespace Oilstone\SystemStatus\Concerns;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Probe;
use Throwable;

/**
 * Rates how much of a quota, such as an API's daily request allowance, has been used. Options
 * read from config, each a percentage from 0 to 100, or null to turn that limit off:
 * - degrade_usage: degrade the check at this much usage
 * - max_usage: fail the check at this much usage
 *
 * Call validateUsageLimits() from configure().
 */
trait RatesUsage
{
    /**
     * The limits used when the config doesn't set them.
     *
     * @return array{degrade_usage: int|float|null, max_usage: int|float|null}
     */
    abstract protected function usageDefaults(): array;

    abstract protected function fail(string $message, ?Throwable $previous = null): never;

    protected function validateUsageLimits(): void
    {
        foreach (['degrade_usage', 'max_usage'] as $key) {
            $value = $this->usageOption($key);

            if ($value !== null && (! is_numeric($value) || $value < 0 || $value > 100)) {
                throw InvalidMonitorConfiguration::invalid($this->name(), sprintf('%s must be a percentage from 0 to 100, or null', $key));
            }
        }

        $degrade = $this->usageLimit('degrade_usage');
        $max = $this->usageLimit('max_usage');

        if ($degrade !== null && $max !== null && $degrade > $max) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'degrade_usage must not be more than max_usage');
        }
    }

    /**
     * Fail at max_usage and degrade at degrade_usage.
     *
     * @param string $label What is being used, e.g. "Daily API"
     * @return float The percentage used
     */
    protected function rateUsage(Probe $probe, string $label, int $used, int $limit): float
    {
        // With no allowance at all, any use is too much
        $percentage = $limit > 0 ? $used / $limit * 100 : 100.0;
        $message = sprintf('%s usage is at %d%% (%d of %d)', $label, floor($percentage), $used, $limit);
        $max = $this->usageLimit('max_usage');
        $degrade = $this->usageLimit('degrade_usage');

        if ($max !== null && $percentage >= $max) {
            $this->fail($message);
        }

        if ($degrade !== null && $percentage >= $degrade) {
            $probe->degrade($message);
        }

        return $percentage;
    }

    protected function usageLimit(string $key): ?float
    {
        $value = $this->usageOption($key);

        return is_numeric($value) ? (float) $value : null;
    }

    private function usageOption(string $key): mixed
    {
        // An explicit null turns off a limit the monitor would otherwise apply
        return array_key_exists($key, $this->config) ? $this->config[$key] : $this->usageDefaults()[$key];
    }
}
