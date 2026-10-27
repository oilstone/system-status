<?php

namespace Oilstone\SystemStatus\Tests\Unit\Monitors;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Monitors\Pdo;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use PDO as NativePdo;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class PdoTest extends TestCase
{
    public function test_it_connects_from_a_dsn_and_runs_the_query(): void
    {
        $this->assertSame(Rating::Optimal, $this->check(['dsn' => 'sqlite::memory:'])->rating);
        $this->assertSame(Rating::Optimal, $this->check(['dsn' => 'sqlite::memory:', 'query' => 'SELECT 1 + 1'])->rating);
    }

    public function test_it_uses_a_given_connection_or_closure(): void
    {
        $pdo = new NativePdo('sqlite::memory:');

        $this->assertSame(Rating::Optimal, $this->check(['pdo' => $pdo])->rating);
        $this->assertSame(Rating::Optimal, $this->check(['pdo' => fn () => $pdo])->rating);
    }

    public function test_it_fails_when_the_query_fails(): void
    {
        $exceptions = $this->check(['dsn' => 'sqlite::memory:', 'query' => 'SELECT * FROM missing']);
        $silent = $this->check(['pdo' => new NativePdo('sqlite::memory:', options: [NativePdo::ATTR_ERRMODE => NativePdo::ERRMODE_SILENT]), 'query' => 'SELECT * FROM missing']);

        $this->assertStringContainsString('no such table: missing', $exceptions->errorMessage());
        $this->assertSame('The query could not be executed', $silent->errorMessage());
    }

    public function test_it_fails_when_it_cannot_connect(): void
    {
        $result = $this->check(['dsn' => 'sqlite:' . sys_get_temp_dir() . '/missing-dir/db.sqlite']);

        $this->assertSame(Rating::Failed, $result->rating);
        $this->assertStringContainsString('unable to open database file', $result->errorMessage());
    }

    public function test_a_connection_must_be_configured(): void
    {
        $this->assertSame('PDO is misconfigured: pdo must be a PDO instance or a closure returning one', $this->check(['pdo' => fn () => null])->errorMessage());

        $this->expectException(InvalidMonitorConfiguration::class);
        $this->expectExceptionMessage('PDO is missing required configuration: dsn');

        new Pdo();
    }

    protected function check(array $config): Result
    {
        return (new SystemStatus())->add(['type' => 'pdo'] + $config)->check()->results()[0];
    }
}
