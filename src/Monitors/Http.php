<?php

/**
 * @noinspection PhpUndefinedClassInspection
 * @noinspection PhpUndefinedNamespaceInspection
 * @noinspection PhpUndefinedMethodInspection
 */

namespace Oilstone\SystemStatus\Monitors;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Oilstone\SystemStatus\Contracts\Monitor as MonitorContract;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\MonitorFailedException;

/**
 * Class Http
 * @package Oilstone\SystemStatus\Monitors
 */
class Http extends Monitor implements MonitorContract
{
    /**
     * @var string
     */
    protected string $alias = 'HTTP monitor';

    /**
     * Http constructor.
     * @param array $config
     * @throws InvalidMonitorConfiguration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        if (!($config['url'] ?? false)) {
            throw new InvalidMonitorConfiguration('Missing URL value for ' . $this->alias, $config);
        }
    }

    /**
     * @throws MonitorFailedException
     */
    protected function executeAction(): void
    {
        $client = new Client($this->config['options'] ?? []);

        try {
            $response = $client->request($this->config['request_type'] ?? 'GET', $this->config['url'], $this->config['options'] ?? []);
        } catch (GuzzleException $e) {
            throw new MonitorFailedException($this->alias . ' encountered an error (' . $e->getMessage() . ')', $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new MonitorFailedException($this->alias . ' encountered an error (Invalid response status: ' . $response->getStatusCode() . ')');
        }
    }
}
