<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Monitors;

use Illuminate\Contracts\Queue\Factory;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

/**
 * Options:
 * - connection: name from config/queue.php (default connection when omitted)
 * - queue: queue name (the connection's default when omitted)
 * - degrade_at: number of waiting jobs that degrades the rating
 * - fail_at: number of waiting jobs that fails the check
 */
class Queue extends Monitor
{
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
        $size = $this->queue->connection($this->config['connection'] ?? null)->size($this->config['queue'] ?? null);

        $probe->meta('size', $size);

        if (isset($this->config['fail_at']) && $size >= $this->config['fail_at']) {
            $this->fail(sprintf('%d jobs are waiting (limit %d)', $size, $this->config['fail_at']));
        }

        if (isset($this->config['degrade_at']) && $size >= $this->config['degrade_at']) {
            $probe->degrade(sprintf('%d jobs are waiting', $size));
        }
    }
}
