<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Contracts\Monitor as MonitorContract;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\MonitorFailedException;

/**
 * Class Redis
 * @package Oilstone\SystemStatus\Monitors
 */
class Redis extends Monitor implements MonitorContract
{
    /**
     * @var string
     */
    protected string $alias = 'Redis monitor';

    /**
     * Redis constructor.
     * @param array $config
     * @throws InvalidMonitorConfiguration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        if (!($config['manager'] ?? false) && !($config['connections'] ?? false)) {
            throw new InvalidMonitorConfiguration('Missing required configuration for ' . $this->alias, $config);
        }
    }

    /**
     * @return void
     * @throws MonitorFailedException
     */
    protected function executeAction(): void
    {
        $manager = $this->config['manager'] ?? null;

        if (!$manager) {
            $redisClass = '\\Oilstone\\RedisCache\\Managers\\' . (($this->config['driver'] ?? 'phpredis') === 'predis' ? 'Predis' : 'PhpRedis') . (($this->config['cluster'] ?? false) ? 'Cluster' : '');

            $manager = new $redisClass([
                'connections' => $this->config['connections'] ?? [],
                'auth' => $this->config['auth'] ?? [],
                'options' => $this->config['options'] ?? [],
            ]);
        }

        $value = $manager->remember($this->config['test_key'], 60, function () {
            return uniqid();
        });

        if ($manager->get($this->config['test_key']) !== $value) {
            throw new MonitorFailedException($this->alias . ' failed to read/write the specified key (' . $this->config['test_key'] . ')');
        }
    }
}
