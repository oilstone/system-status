<?php

/**
 * @noinspection PhpComposerExtensionStubsInspection
 */

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Contracts\Monitor as MonitorContract;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\MonitorFailedException;
use PDO;
use PDOException;

/**
 * Class MySql
 * @package Oilstone\SystemStatus\Monitors
 */
class MySql extends Monitor implements MonitorContract
{
    /**
     * @var string
     */
    protected string $alias = 'MySQL monitor';

    /**
     * MySQL constructor.
     * @param array $config
     * @throws InvalidMonitorConfiguration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        if (!($config['pdo'] ?? false) && !(($config['host'] ?? false) && ($config['user'] ?? false) && ($config['password'] ?? false) && ($config['database'] ?? false))) {
            throw new InvalidMonitorConfiguration('Missing required database configuration value for ' . $this->alias, $config);
        }
    }

    /**
     * @throws MonitorFailedException
     */
    protected function executeAction(): void
    {
        try {
            $dbh = $config['pdo'] ?? new PDO('mysql:host=' . $this->config['host'] . ';dbname=' . $this->config['database'], $this->config['user'], $this->config['password']);

            if (isset($this->config['table'])) {
                $dbh->query('SELECT * from ' . $this->config['table']);
            }

            if (!isset($config['pdo'])) {
                $dbh = null;
            }
        } catch (PDOException $e) {
            throw new MonitorFailedException($this->alias . ' encountered an error (' . $e->getMessage() . ')', $e);
        }
    }
}
