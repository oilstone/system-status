<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Probe;

/**
 * Options:
 * - path: file or directory to check
 * - checks: any of "exists", "readable", "writable" (default ["exists"])
 * - max_age: fail if the file was last modified longer ago than this many seconds
 */
class File extends Monitor
{
    protected const CHECKS = ['exists', 'readable', 'writable'];

    protected string $name = 'File';

    protected array $required = ['path'];

    public function check(Probe $probe): void
    {
        $path = (string) $this->config['path'];

        clearstatcache(true, $path);

        foreach ($this->checks() as $check) {
            $passes = match ($check) {
                'exists' => file_exists($path),
                'readable' => is_readable($path),
                'writable' => is_writable($path),
                default => $this->fail(sprintf('Unknown check "%s"', $check)),
            };

            if (! $passes) {
                $this->fail(sprintf('%s is not %s', $path, $check === 'exists' ? 'present' : $check));
            }
        }

        if (isset($this->config['max_age'])) {
            $this->checkAge($path, (int) $this->config['max_age'], $probe);
        }
    }

    protected function configure(): void
    {
        $unknown = array_diff($this->checks(), static::CHECKS);

        if ($unknown) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'unknown checks ' . implode(', ', $unknown));
        }
    }

    protected function checkAge(string $path, int $maxAge, Probe $probe): void
    {
        $modified = @filemtime($path);

        if ($modified === false) {
            $this->fail(sprintf('Unable to read the modified time of %s', $path));
        }

        $age = time() - $modified;

        $probe->meta('age_seconds', $age);

        if ($age > $maxAge) {
            $this->fail(sprintf('%s was last modified %ds ago (limit %ds)', $path, $age, $maxAge));
        }
    }

    /**
     * @return list<string>
     */
    protected function checks(): array
    {
        return array_values((array) ($this->config['checks'] ?? ['exists']));
    }
}
