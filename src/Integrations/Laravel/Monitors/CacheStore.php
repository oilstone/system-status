<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Monitors;

use Illuminate\Contracts\Cache\Factory;
use Oilstone\SystemStatus\Monitors\Cache;

/**
 * Options:
 * - store: name from config/cache.php (default store when omitted)
 * - key_prefix, ttl: see Cache
 */
class CacheStore extends Cache
{
    protected ?string $storeName;

    public function __construct(array $config, Factory $cache)
    {
        $this->storeName = is_string($config['store'] ?? null) ? $config['store'] : null;

        if (! is_object($config['store'] ?? null)) {
            $config['store'] = fn () => $cache->store($this->storeName);
        }

        parent::__construct($config);
    }

    public function name(): string
    {
        return $this->config['name'] ?? sprintf('Cache (%s)', $this->storeName ?? 'default');
    }
}
