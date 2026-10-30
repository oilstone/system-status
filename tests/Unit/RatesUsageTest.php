<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\Tests\Fixtures\QuotaMonitor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RatesUsageTest extends TestCase
{
    public function test_usage_below_the_limits_is_optimal(): void
    {
        $monitor = new QuotaMonitor(['used' => 11999, 'limit' => 15000]);
        $result = Probe::run($monitor, 'services');

        $this->assertSame(Rating::Optimal, $result->rating);
        $this->assertSame([], $result->notes);
        $this->assertEqualsWithDelta(79.99, $monitor->percentage, 0.01);
    }

    public function test_usage_degrades_at_degrade_usage(): void
    {
        $result = $this->check(['used' => 12500, 'limit' => 15000, 'degrade_usage' => 80]);

        $this->assertSame(Rating::Degraded, $result->rating);
        $this->assertSame(['Daily API usage is at 83% (12500 of 15000)'], $result->notes);
        $this->assertSame(Rating::Degraded, $this->check(['used' => 80, 'limit' => 100])->rating);
    }

    public function test_usage_fails_at_max_usage(): void
    {
        $result = $this->check(['used' => 14250, 'limit' => 15000]);

        $this->assertSame(Rating::Failed, $result->rating);
        $this->assertSame('Daily API usage is at 95% (14250 of 15000)', $result->errorMessage());
        $this->assertSame('Daily API usage is at 100% (0 of 0)', $this->check(['used' => 0, 'limit' => 0])->errorMessage());
    }

    public function test_limits_can_be_changed_or_turned_off(): void
    {
        $this->assertSame(Rating::Optimal, $this->check(['used' => 99, 'limit' => 100, 'degrade_usage' => null, 'max_usage' => null])->rating);
        $this->assertSame(Rating::Degraded, $this->check(['used' => 99, 'limit' => 100, 'max_usage' => null])->rating);
        $this->assertSame(Rating::Optimal, $this->check(['used' => 85, 'limit' => 100, 'degrade_usage' => '90.5', 'max_usage' => 100])->rating);
        $this->assertSame(Rating::Failed, $this->check(['used' => 50, 'limit' => 100, 'degrade_usage' => 0, 'max_usage' => 50])->rating);
    }

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function invalidLimits(): array
    {
        return [
            'negative' => [['degrade_usage' => -1], 'degrade_usage must be a percentage from 0 to 100, or null'],
            'over 100' => [['max_usage' => 101], 'max_usage must be a percentage from 0 to 100, or null'],
            'text' => [['max_usage' => 'most'], 'max_usage must be a percentage from 0 to 100, or null'],
            'inverted' => [['degrade_usage' => 90, 'max_usage' => 80], 'degrade_usage must not be more than max_usage'],
        ];
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_limits_are_rejected(array $config, string $message): void
    {
        $this->expectException(InvalidMonitorConfiguration::class);
        $this->expectExceptionMessage('Quota is misconfigured: ' . $message);

        new QuotaMonitor($config);
    }

    protected function check(array $config): Result
    {
        return Probe::run(new QuotaMonitor($config), 'services');
    }
}
