<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Monitors;

use Illuminate\Contracts\Queue\Factory;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

/**
 * Options:
 * - connection: name from config/queue.php (default connection when omitted)
 * - queue: queue name (the connection's default when omitted)
 * - degrade_waiting, max_waiting: degrade or fail when more jobs than this are waiting
 * - degrade_age, max_age: degrade or fail when the oldest waiting job is older than this many seconds
 */
class Queue extends Monitor
{
    protected const LIMITS = ['degrade_waiting', 'max_waiting', 'degrade_age', 'max_age'];

    protected string $name = 'Queue';

    public function __construct(array $config, protected Factory $queue)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return $this->config['name'] ?? sprintf(
            'Queue (%s)',
            implode(':', array_filter([$this->config['connection'] ?? 'default', $this->config['queue'] ?? null])),
        );
    }

    public function check(Probe $probe): void
    {
        $connection = $this->queue->connection($this->config['connection'] ?? null);
        $queue = $this->config['queue'] ?? null;

        // size() also counts delayed and reserved jobs, which are not waiting to be picked up
        $waiting = (int) (method_exists($connection, 'pendingSize') ? $connection->pendingSize($queue) : $connection->size($queue));

        $probe->meta('waiting', $waiting);
        $this->limit($probe, 'waiting', $waiting, '%d jobs are waiting');

        if (! isset($this->config['degrade_age']) && ! isset($this->config['max_age'])) {
            return;
        }

        if (! method_exists($connection, 'creationTimeOfOldestPendingJob')) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'this queue connection cannot report the age of its oldest job');
        }

        $created = $connection->creationTimeOfOldestPendingJob($queue);
        $age = $created === null ? 0 : max(0, time() - (int) $created);

        $probe->meta('oldest_age_seconds', $age);
        $this->limit($probe, 'age', $age, 'The oldest job has waited %ds');
    }

    protected function configure(): void
    {
        foreach (static::LIMITS as $key) {
            if (isset($this->config[$key]) && (! is_numeric($this->config[$key]) || $this->config[$key] < 0)) {
                throw InvalidMonitorConfiguration::invalid($this->name(), sprintf('%s must be a number of at least 0', $key));
            }
        }
    }

    protected function limit(Probe $probe, string $measure, int $value, string $description): void
    {
        $max = $this->config["max_{$measure}"] ?? null;
        $degrade = $this->config["degrade_{$measure}"] ?? null;

        if ($max !== null && $value > $max) {
            $this->fail(sprintf($description . ' (limit %d)', $value, $max));
        }

        if ($degrade !== null && $value > $degrade) {
            $probe->degrade(sprintf($description, $value));
        }
    }
}
