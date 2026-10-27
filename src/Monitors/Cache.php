<?php

namespace Oilstone\SystemStatus\Monitors;

use Closure;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Probe;
use Psr\SimpleCache\CacheInterface;

/**
 * Writes, reads back and deletes a unique key, so concurrent checks from several servers never collide.
 *
 * Options:
 * - store: a PSR-16 cache, or a closure returning one
 * - key_prefix (default "system-status-")
 * - ttl: seconds, in case the delete never happens (default 60)
 */
class Cache extends Monitor
{
    protected string $name = 'Cache';

    protected array $required = ['store'];

    public function check(Probe $probe): void
    {
        $store = $this->store();
        $key = ($this->config['key_prefix'] ?? 'system-status-') . bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(8));

        if (! $store->set($key, $value, (int) ($this->config['ttl'] ?? 60))) {
            $this->fail('Unable to write to the cache');
        }

        try {
            if ($store->get($key) !== $value) {
                $this->fail('The cache returned a different value to the one written');
            }
        } finally {
            $store->delete($key);
        }
    }

    protected function store(): CacheInterface
    {
        $store = $this->config['store'];

        if ($store instanceof Closure) {
            $store = $store();
        }

        if (! $store instanceof CacheInterface) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'store must implement ' . CacheInterface::class);
        }

        return $store;
    }
}
