<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Tests\Fixtures\FakeMonitor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MonitorTest extends TestCase
{
    public function test_required_keys_must_be_present_and_not_blank(): void
    {
        $monitor = fn (array $config) => new class($config) extends Monitor
        {
            protected string $name = 'Needy';

            protected array $required = ['a', 'b', 'c', 'd'];

            public function check(Probe $probe): void
            {
            }
        };

        $this->expectException(InvalidMonitorConfiguration::class);
        $this->expectExceptionMessage('Needy is missing required configuration: a, b, c');

        $monitor(['a' => '', 'b' => [], 'c' => null, 'd' => 0]);
    }

    public function test_the_name_option_overrides_the_default_name(): void
    {
        $this->assertSame('Fake', (new FakeMonitor())->name());
        $this->assertSame('Search', (new FakeMonitor(['name' => 'Search']))->name());
    }

    public function test_checks_are_optimal_without_thresholds(): void
    {
        $this->assertSame(Rating::Optimal, (new FakeMonitor())->rate(60_000));
    }

    public static function durations(): array
    {
        return [
            'under the first limit' => [99.9, Rating::Optimal],
            'at a limit counts as over it' => [100, Rating::Acceptable],
            'under the second limit' => [499, Rating::Acceptable],
            'slower than every limit' => [500, Rating::Degraded],
        ];
    }

    #[DataProvider('durations')]
    public function test_thresholds_rate_by_duration_in_any_order(float $duration, Rating $expected): void
    {
        $monitor = new FakeMonitor(['thresholds' => [500 => 'acceptable', 100 => Rating::Optimal]]);

        $this->assertSame($expected, $monitor->rate($duration));
    }

    public function test_otherwise_rates_checks_slower_than_every_threshold(): void
    {
        $monitor = new FakeMonitor(['thresholds' => ['100' => 'optimal'], 'otherwise' => 'failed']);

        $this->assertSame(Rating::Failed, $monitor->rate(100));
    }

    public function test_class_thresholds_apply_unless_overridden(): void
    {
        $monitor = fn (array $config = []) => new class($config) extends FakeMonitor
        {
            protected array $thresholds = [100 => 'optimal'];
        };

        $this->assertSame(Rating::Degraded, $monitor()->rate(150));
        $this->assertSame(Rating::Optimal, $monitor(['thresholds' => [200 => 'optimal']])->rate(150));
    }

    public static function invalidThresholds(): array
    {
        return [
            'not an array' => [['thresholds' => '200'], 'thresholds must map milliseconds to ratings'],
            'non-integer limit' => [['thresholds' => ['fast' => 'optimal']], 'threshold "fast" is not a whole number of milliseconds'],
            'fractional limit' => [['thresholds' => ['1.5' => 'optimal']], 'threshold "1.5" is not a whole number of milliseconds'],
            'unknown rating' => [['thresholds' => [200 => 'great']], 'threshold 200ms is not mapped to a valid rating'],
            'non-scalar rating' => [['thresholds' => [200 => ['optimal']]], 'threshold 200ms is not mapped to a valid rating'],
            'unknown otherwise' => [['otherwise' => 'meh'], 'otherwise is not a valid rating'],
        ];
    }

    #[DataProvider('invalidThresholds')]
    public function test_invalid_thresholds_fail_when_the_monitor_is_built(array $config, string $reason): void
    {
        $this->expectException(InvalidMonitorConfiguration::class);
        $this->expectExceptionMessage('Fake is misconfigured: ' . $reason);

        new FakeMonitor($config);
    }
}
