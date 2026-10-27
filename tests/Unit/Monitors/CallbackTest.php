<?php

namespace Oilstone\SystemStatus\Tests\Unit\Monitors;

use Illuminate\Container\Container;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Monitors\Callback;
use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\Tests\Fixtures\InjectedCheck;
use Oilstone\SystemStatus\Tests\Fixtures\InvokableCheck;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CallbackTest extends TestCase
{
    protected function setUp(): void
    {
        InvokableCheck::reset();
    }

    public function test_callbacks_pass_unless_they_return_false_or_throw(): void
    {
        $this->assertSame(Rating::Optimal, $this->check(fn () => null)->rating);
        $this->assertSame(Rating::Optimal, $this->check(fn () => true)->rating);
        $this->assertSame('Callback returned false', $this->check(fn () => false)->errorMessage());
        $this->assertSame('Down', $this->check(fn () => throw new RuntimeException('Down'))->errorMessage());
        $this->assertSame('Search returned false', $this->check(fn () => false, ['name' => 'Search'])->errorMessage());
    }

    public function test_callbacks_receive_the_probe_and_options(): void
    {
        $result = $this->check(function (Probe $probe, array $config) {
            $probe->degrade('Slow')->meta('region', $config['region']);
        }, ['region' => 'eu']);

        $this->assertSame(Rating::Degraded, $result->rating);
        $this->assertSame(['region' => 'eu'], $result->meta);
    }

    public function test_invokable_classes_are_instantiated(): void
    {
        $result = $this->check(InvokableCheck::class, ['region' => 'eu']);

        $this->assertSame(Rating::Optimal, $result->rating);
        $this->assertSame(1, InvokableCheck::$calls);
        $this->assertSame('eu', $result->meta['config']['region']);
    }

    public function test_invokable_classes_are_resolved_through_a_container_when_given(): void
    {
        $monitor = new Callback(['callback' => InjectedCheck::class], new Container());

        $result = (new SystemStatus())->add($monitor)->check()->results()[0];

        $this->assertSame(['dependency' => 'injected'], $result->meta);
    }

    public function test_uncallable_callbacks_fail(): void
    {
        $this->assertSame('Callback is misconfigured: the callback is not callable', $this->check('not_a_function')->errorMessage());
    }

    public function test_a_callback_is_required(): void
    {
        $this->expectException(InvalidMonitorConfiguration::class);

        new Callback();
    }

    protected function check(mixed $callback, array $config = []): Result
    {
        return (new SystemStatus())->add(['type' => 'callback', 'callback' => $callback] + $config)->check()->results()[0];
    }
}
