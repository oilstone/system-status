<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Monitors;

use Illuminate\Database\DatabaseManager;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;

/**
 * Options:
 * - connection: name from config/database.php (default connection when omitted)
 * - query (default "select 1")
 *
 * Connect timeouts come from the connection's own PDO options, e.g. PDO::ATTR_TIMEOUT.
 */
class Database extends Monitor
{
    protected string $name = 'Database';

    public function __construct(array $config, protected DatabaseManager $db)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return $this->config['name'] ?? sprintf('Database (%s)', $this->connectionName());
    }

    public function check(Probe $probe): void
    {
        $this->db->connection($this->connectionName())->select($this->config['query'] ?? 'select 1');
    }

    protected function connectionName(): string
    {
        return $this->config['connection'] ?? $this->db->getDefaultConnection();
    }
}
