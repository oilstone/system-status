<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Contracts\Monitor as MonitorContract;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\MonitorFailedException;

/**
 * Class File
 * @package Oilstone\SystemStatus\Monitors
 */
class File extends Monitor implements MonitorContract
{
    /**
     * @var string
     */
    protected string $alias = 'File monitor';

    /**
     * File constructor.
     * @param array $config
     * @throws InvalidMonitorConfiguration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        if (!($config['test_type'] ?? false) || !($config['file_path'] ?? false)) {
            throw new InvalidMonitorConfiguration('Missing required configuration for ' . $this->alias, $config);
        }
    }

    /**
     * @return void
     */
    protected function executeAction(): void
    {
        $this->{$this->config['test_type']}();
    }

    /**
     * @throws MonitorFailedException
     */
    protected function exists(): void
    {
        if (!file_exists($this->config['file_path'])) {
            throw new MonitorFailedException($this->alias . ' was unable to find the specified file (' . $this->config['file_path'] . ')');
        }
    }

    /**
     * @throws MonitorFailedException
     */
    protected function write(): void
    {
        if (!is_writable($this->config['file_path'])) {
            throw new MonitorFailedException($this->alias . ' was unable to write to the specified file (' . $this->config['file_path'] . ')');
        }
    }

    /**
     * @throws MonitorFailedException
     */
    protected function read(): void
    {
        if (!is_readable($this->config['file_path'])) {
            throw new MonitorFailedException($this->alias . ' was unable to read the specified file (' . $this->config['file_path'] . ')');
        }
    }
}
