<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Illuminate\Support\Facades\Log;
use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Tests\Fixtures\InvokableCheck;
use RuntimeException;

class PingEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        InvokableCheck::reset();

        $this->monitors([
            'connections' => [['type' => 'callback', 'name' => 'Database', 'callback' => InvokableCheck::class]],
            'services' => [['type' => 'callback', 'name' => 'API', 'callback' => fn () => throw new RuntimeException('API down')]],
        ]);
    }

    public function test_it_passes_over_the_configured_categories(): void
    {
        $this->rebootWith(['system-status.ping.categories' => ['connections']]);

        $response = $this->get('/monitors/ping')
            ->assertOk()
            ->assertSeeText('OK')
            ->assertHeader('Cache-Control', 'no-store, private');

        // Symfony versions differ in the charset's case
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_it_fails_and_logs_the_failing_monitors(): void
    {
        Log::spy();

        $this->get('/monitors/ping')->assertStatus(503)->assertSeeText('FAIL');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'System status ping failed'
            && $context['failures'] === [['category' => 'services', 'name' => 'API', 'rating' => 'failed', 'error' => 'API down', 'notes' => []]]);
    }

    public function test_fail_at_can_fail_degraded_monitors(): void
    {
        $this->rebootWith([
            'system-status.ping.categories' => ['connections'],
            'system-status.ping.fail_at' => 'degraded',
            'system-status.monitors.connections' => [['type' => 'callback', 'callback' => fn (Probe $probe) => $probe->degrade('Slow')]],
        ]);

        $this->get('/monitors/ping')->assertStatus(503);
    }

    public function test_misconfiguration_fails_without_throwing(): void
    {
        $this->rebootWith(['system-status.ping.categories' => ['missing']]);

        Log::spy();

        $this->get('/monitors/ping')->assertStatus(503)->assertSeeText('FAIL');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['exception'] === 'Unknown monitor categories: missing');
    }

    public function test_logging_can_be_disabled(): void
    {
        $this->rebootWith(['system-status.ping.log_failures' => false]);

        Log::spy();

        $this->get('/monitors/ping')->assertStatus(503);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_outcomes_are_cached_for_ten_seconds_by_default(): void
    {
        $this->rebootWith(['system-status.ping.categories' => ['connections']]);

        $this->assertSame(10, config('system-status.ping.cache_seconds'));

        $this->get('/monitors/ping')->assertOk();

        InvokableCheck::$passes = false;

        $this->get('/monitors/ping')->assertOk();
        $this->assertSame(1, InvokableCheck::$calls);

        $this->travel(11)->seconds();

        $this->get('/monitors/ping')->assertStatus(503);
        $this->assertSame(2, InvokableCheck::$calls);
    }

    public function test_failures_are_cached_and_logged_once(): void
    {
        Log::spy();

        $this->get('/monitors/ping')->assertStatus(503);
        $this->get('/monitors/ping')->assertStatus(503);

        $this->assertSame(1, InvokableCheck::$calls);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_checks_live_when_caching_is_off_or_the_store_is_broken(): void
    {
        $this->rebootWith(['system-status.ping.categories' => ['connections'], 'system-status.ping.cache_seconds' => 0]);

        $this->get('/monitors/ping')->assertOk();
        $this->get('/monitors/ping')->assertOk();
        $this->assertSame(2, InvokableCheck::$calls);

        $this->rebootWith(['system-status.ping.cache_seconds' => 10, 'system-status.ping.cache_store' => 'missing']);

        $this->get('/monitors/ping')->assertOk();
        $this->get('/monitors/ping')->assertOk();
        $this->assertSame(4, InvokableCheck::$calls);
    }
}
