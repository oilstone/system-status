<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\UnknownMonitorType;
use Oilstone\SystemStatus\MonitorFactory;
use Oilstone\SystemStatus\Monitors\File;
use Oilstone\SystemStatus\Monitors\Http;
use Oilstone\SystemStatus\Monitors\OAuthToken;
use Oilstone\SystemStatus\Tests\Fixtures\FakeMonitor;
use PHPUnit\Framework\TestCase;
use stdClass;

class MonitorFactoryTest extends TestCase
{
    public function test_it_builds_every_kind_of_definition(): void
    {
        $factory = (new MonitorFactory())->extend('fake', FakeMonitor::class);
        $instance = new FakeMonitor();

        $this->assertSame($instance, $factory->make($instance));
        $this->assertSame($instance, $factory->make(fn () => $instance));
        $this->assertInstanceOf(FakeMonitor::class, $factory->make(FakeMonitor::class));
        $this->assertInstanceOf(FakeMonitor::class, $factory->make('fake'));
        $this->assertSame('By class', $factory->make(['class' => FakeMonitor::class, 'name' => 'By class'])->name());
        $this->assertSame('By type', $factory->make(['type' => 'fake', 'name' => 'By type'])->name());
    }

    public function test_type_names_are_normalised(): void
    {
        $factory = new MonitorFactory();

        $this->assertTrue($factory->hasType('OAuth-Token'));
        $this->assertTrue($factory->hasType('oauth token'));
        $this->assertInstanceOf(OAuthToken::class, $factory->make([
            'type' => 'OAuth-Token',
            'token_url' => 'https://idp.test/token',
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]));
    }

    public function test_class_and_type_keys_are_not_passed_as_options(): void
    {
        $monitor = (new MonitorFactory())->make(['type' => 'http', 'url' => 'https://example.test']);

        $this->assertInstanceOf(Http::class, $monitor);
        $this->assertSame('HTTP', $monitor->name());
    }

    public function test_closure_resolvers_receive_the_options(): void
    {
        $factory = (new MonitorFactory())->extend('custom', fn (array $options) => new FakeMonitor($options + ['name' => 'Custom']));

        $this->assertSame('Custom', $factory->make(['type' => 'custom'])->name());
        $this->assertSame('Renamed', $factory->make(['type' => 'custom', 'name' => 'Renamed'])->name());
    }

    public function test_types_can_be_replaced(): void
    {
        $factory = (new MonitorFactory())->extend('file', FakeMonitor::class);

        $this->assertInstanceOf(FakeMonitor::class, $factory->make('file'));
    }

    public function test_unknown_types_and_classes_are_rejected(): void
    {
        $factory = new MonitorFactory();

        $this->assertThrows(fn () => $factory->make(['type' => 'nope']), UnknownMonitorType::class, 'No monitor type named "nope" has been registered');
        $this->assertThrows(fn () => $factory->make(stdClass::class), UnknownMonitorType::class, '"stdClass" does not implement');
        $this->assertThrows(fn () => $factory->make(['options' => true]), UnknownMonitorType::class, 'definition of type array');
        $this->assertThrows(fn () => $factory->make(42), UnknownMonitorType::class, 'definition of type int');
    }

    public function test_closures_must_return_a_monitor(): void
    {
        $factory = (new MonitorFactory())->extend('broken', fn () => 'nope');

        $this->assertThrows(fn () => $factory->make(fn () => null), InvalidMonitorConfiguration::class, 'Closure definition is misconfigured: it returned null rather than a monitor');
        $this->assertThrows(fn () => $factory->make('broken'), InvalidMonitorConfiguration::class, 'Resolver for type "broken" is misconfigured: it returned string');
    }

    public function test_names_can_be_found_without_building_the_monitor(): void
    {
        $factory = (new MonitorFactory())->extend('closure', fn () => new FakeMonitor());

        $this->assertSame('Fake', $factory->nameFor(new FakeMonitor()));
        $this->assertSame('Named', $factory->nameFor(['type' => 'file', 'name' => 'Named']));
        $this->assertSame('File', $factory->nameFor(['type' => 'file']));
        $this->assertSame('File', $factory->nameFor('file'));
        $this->assertSame('File', $factory->nameFor(File::class));
        $this->assertSame('Fake', $factory->nameFor(['class' => FakeMonitor::class]));
        $this->assertSame('closure', $factory->nameFor('closure'));
        $this->assertSame('closure', $factory->nameFor(['type' => 'closure']));
        $this->assertSame('Missing\\Monitor', $factory->nameFor(['class' => 'Missing\\Monitor']));
        $this->assertSame('Unnamed monitor', $factory->nameFor(fn () => null));
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    protected function assertThrows(callable $callback, string $exception, string $message): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail("Expected {$exception} to be thrown");
    }
}
