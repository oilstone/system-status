<?php

namespace Oilstone\SystemStatus\Monitors;

use Closure;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Probe;

/**
 * Options, either:
 * - pdo: a PDO instance, or a closure returning one so nothing connects until the check runs
 * or:
 * - dsn, username, password, options (PDO attributes), timeout (connect timeout seconds, default 5)
 * plus:
 * - query (default "SELECT 1")
 */
class Pdo extends Monitor
{
    protected string $name = 'PDO';

    public function check(Probe $probe): void
    {
        $statement = $this->connect()->query($this->config['query'] ?? 'SELECT 1');

        if ($statement === false) {
            $this->fail('The query could not be executed');
        }

        $statement->closeCursor();
    }

    protected function configure(): void
    {
        if (! isset($this->config['pdo']) && $this->blank($this->config['dsn'] ?? null)) {
            throw InvalidMonitorConfiguration::missing($this->name(), ['dsn']);
        }
    }

    protected function connect(): \PDO
    {
        $pdo = $this->config['pdo'] ?? null;

        if ($pdo instanceof Closure) {
            $pdo = $pdo();
        }

        if ($pdo instanceof \PDO) {
            return $pdo;
        }

        if ($pdo !== null) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'pdo must be a PDO instance or a closure returning one');
        }

        return new \PDO(
            $this->config['dsn'],
            $this->config['username'] ?? null,
            $this->config['password'] ?? null,
            ($this->config['options'] ?? []) + [
                \PDO::ATTR_TIMEOUT => (int) ceil($this->config['timeout'] ?? 5),
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ],
        );
    }
}
