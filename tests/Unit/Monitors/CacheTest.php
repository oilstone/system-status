<?php

namespace Oilstone\SystemStatus\Tests\Unit\Monitors;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class CacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_writes_reads_and_deletes_a_unique_key(): void
    {
        $store = new Repository(new ArrayStore());

        $this->assertSame(Rating::Optimal, $this->check(['store' => $store])->rating);
        $this->assertSame(Rating::Optimal, $this->check(['store' => fn () => $store])->rating);

        $values = [];
        $mock = Mockery::mock(CacheInterface::class);
        $mock->shouldReceive('set')->once()->andReturnUsing(function (string $key, string $value) use (&$values) {
            $values[$key] = $value;

            return true;
        });
        $mock->shouldReceive('get')->once()->andReturnUsing(function (string $key) use (&$values) {
            return $values[$key] ?? null;
        });
        $mock->shouldReceive('delete')->once()->andReturnUsing(function (string $key) use (&$values) {
            unset($values[$key]);

            return true;
        });

        $this->assertSame(Rating::Optimal, $this->check(['store' => $mock])->rating);
        $this->assertSame([], $values);
    }

    public function test_it_fails_when_the_value_cannot_be_written(): void
    {
        $store = Mockery::mock(CacheInterface::class);
        $store->shouldReceive('set')->once()->with(Mockery::pattern('/^app-health-[0-9a-f]{16}$/'), Mockery::type('string'), 30)->andReturn(false);

        $this->assertSame('Unable to write to the cache', $this->check(['store' => $store, 'key_prefix' => 'app-health-', 'ttl' => 30])->errorMessage());
    }

    public function test_it_fails_and_cleans_up_when_a_different_value_is_read(): void
    {
        $store = Mockery::mock(CacheInterface::class);
        $store->shouldReceive('set')->andReturn(true);
        $store->shouldReceive('get')->andReturn('stale');
        $store->shouldReceive('delete')->once()->andReturn(true);

        $this->assertSame('The cache returned a different value to the one written', $this->check(['store' => $store])->errorMessage());
    }

    public function test_the_store_must_be_a_psr16_cache(): void
    {
        $result = $this->check(['store' => fn () => 'redis']);

        $this->assertSame(Rating::Failed, $result->rating);
        $this->assertSame('Cache is misconfigured: store must implement Psr\SimpleCache\CacheInterface', $result->errorMessage());
    }

    protected function check(array $config): Result
    {
        return (new SystemStatus())->add(['type' => 'cache'] + $config)->check()->results()[0];
    }
}
