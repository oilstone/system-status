<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Mockery;
use Oilstone\SystemStatus\Exceptions\UnknownMonitorType;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Oilstone\SystemStatus\Integrations\Laravel\Events\MonitorRecovered;
use Oilstone\SystemStatus\Integrations\Laravel\Events\MonitorWentDown;
use Oilstone\SystemStatus\Tests\Fixtures\RecordingWatchHandler;
use Oilstone\SystemStatus\WatchState;
use Oilstone\SystemStatus\WatchStore;

class WatchesTest extends TestCase
{
    public static bool $healthy = true;

    protected array $config = [
        'system-status.status.token' => 'status-token',
        'system-status.status.public_environments' => [],
        'system-status.watches.cache_store' => 'array',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        static::$healthy = true;
        RecordingWatchHandler::$calls = [];

        $this->watches([
            'payments' => [
                'monitor' => ['type' => 'callback', 'name' => 'Payments API', 'callback' => fn () => static::$healthy],
                'failures' => 2,
                'handler' => RecordingWatchHandler::class,
                'cron' => '*/5 * * * *',
                'channel' => '#ops',
            ],
            'search' => ['monitor' => ['type' => 'file', 'path' => __FILE__, 'name' => 'Search index']],
        ]);
    }

    public function test_it_runs_every_watch_and_prints_the_outcomes(): void
    {
        $this->artisan('system-status:watch')
            ->expectsOutputToContain('payments')
            ->expectsOutputToContain('Payments API: optimal')
            ->expectsOutputToContain('1 consecutive pass')
            ->expectsOutputToContain('search')
            ->expectsOutputToContain('Search index: optimal')
            ->assertSuccessful();

        $this->assertCount(1, RecordingWatchHandler::$calls);
        $this->assertSame(['channel' => '#ops'], RecordingWatchHandler::$calls[0]['options']);
        $this->assertSame(1, $this->store()->get('search')->passes);
    }

    public function test_it_runs_one_watch_through_its_thresholds(): void
    {
        static::$healthy = false;

        $this->artisan('system-status:watch payments')
            ->expectsOutputToContain('(Payments API returned false)')
            ->expectsOutputToContain('1 consecutive failure; down at 2')
            ->assertSuccessful();

        $this->artisan('system-status:watch payments')
            ->expectsOutputToContain('DOWN went down')
            ->expectsOutputToContain('2 consecutive failures')
            ->assertSuccessful();

        static::$healthy = true;

        $this->artisan('system-status:watch payments')->expectsOutputToContain('UP recovered')->assertSuccessful();

        $this->assertNull($this->store()->get('search')->checkedAt);
        $this->assertCount(3, RecordingWatchHandler::$calls);
    }

    public function test_unknown_watches_are_rejected(): void
    {
        $this->artisan('system-status:watch nope')
            ->expectsOutputToContain('No watch named "nope" is defined')
            ->assertExitCode(2);

        $this->watches([]);

        $this->artisan('system-status:watch')->expectsOutputToContain('No watches are defined')->assertSuccessful();
    }

    public function test_a_watch_that_is_already_running_is_skipped(): void
    {
        $lock = Cache::store('array')->lock('system-status.watch.payments.lock', 60);
        $lock->get();

        $this->artisan('system-status:watch')
            ->expectsOutputToContain('Skipped the payments watch, which is already running')
            ->expectsOutputToContain('Search index')
            ->assertSuccessful();

        $this->assertSame([], RecordingWatchHandler::$calls);

        $lock->release();

        $this->artisan('system-status:watch payments')->assertSuccessful();

        $this->assertCount(1, RecordingWatchHandler::$calls);
        $this->assertTrue(Cache::store('array')->lock('system-status.watch.payments.lock', 60)->get(), 'The lock is released after the run');
    }

    public function test_a_monitor_that_cannot_be_built_fails_and_is_reported(): void
    {
        $this->watches(['payments' => ['monitor' => ['type' => 'nope'], 'handler' => RecordingWatchHandler::class]]);

        $exceptions = $this->spy(ExceptionHandler::class);

        $this->artisan('system-status:watch')
            ->expectsOutputToContain('No monitor type named "nope" has been registered')
            ->assertFailed();

        $exceptions->shouldHaveReceived('report')->once()->with(Mockery::type(UnknownMonitorType::class));
        $this->assertNull($this->store()->get('payments')->checkedAt);
        $this->assertSame([], RecordingWatchHandler::$calls);
    }

    public function test_misconfigured_watches_fail_without_stopping_the_others(): void
    {
        $this->watches([
            'payments' => ['monitor' => 'file', 'failures' => 0],
            'search' => ['monitor' => ['type' => 'file', 'path' => __FILE__, 'name' => 'Search index']],
        ]);

        $exceptions = $this->spy(ExceptionHandler::class);

        $this->artisan('system-status:watch')
            ->expectsOutputToContain('The payments watch is misconfigured: failures must be a whole number of at least 1')
            ->expectsOutputToContain('Search index: optimal')
            ->assertFailed();

        $exceptions->shouldHaveReceived('report')->once()->with(Mockery::type(InvalidArgumentException::class));
    }

    public function test_a_failing_handler_still_prints_the_outcome_and_fails(): void
    {
        $this->watches(['payments' => ['monitor' => ['type' => 'callback', 'callback' => fn () => false], 'handler' => RecordingWatchHandler::class, 'throw' => true]]);

        $exceptions = $this->spy(ExceptionHandler::class);

        $this->artisan('system-status:watch payments')
            ->expectsOutputToContain('DOWN went down')
            ->expectsOutputToContain('The payments watch handler failed: Failover API refused the request')
            ->assertFailed();

        $exceptions->shouldHaveReceived('report')->once()->with(Mockery::type(WatchHandlerFailed::class));
        $this->assertSame(WatchState::Down, $this->store()->get('payments')->state);
    }

    public function test_handlers_are_built_through_the_container(): void
    {
        $resolved = 0;
        $this->app->resolving(RecordingWatchHandler::class, function () use (&$resolved) {
            $resolved++;
        });

        $this->artisan('system-status:watch payments')->assertSuccessful();

        $this->assertSame(1, $resolved);
    }

    public function test_events_are_dispatched_on_transitions_only(): void
    {
        Event::fake([MonitorWentDown::class, MonitorRecovered::class]);

        static::$healthy = false;
        $this->artisan('system-status:watch payments');

        Event::assertNotDispatched(MonitorWentDown::class);

        $this->artisan('system-status:watch payments');
        $this->artisan('system-status:watch payments');

        Event::assertDispatchedTimes(MonitorWentDown::class, 1);
        Event::assertDispatched(MonitorWentDown::class, fn (MonitorWentDown $event) => $event->outcome->watch === 'payments' && $event->outcome->failures === 2);

        static::$healthy = true;
        $this->artisan('system-status:watch payments');
        $this->artisan('system-status:watch payments');

        Event::assertDispatchedTimes(MonitorRecovered::class, 1);
        Event::assertDispatched(MonitorRecovered::class, fn (MonitorRecovered $event) => $event->outcome->previous === WatchState::Down);
    }

    public function test_events_are_dispatched_when_the_handler_fails(): void
    {
        $this->watches(['payments' => ['monitor' => ['type' => 'callback', 'callback' => fn () => false], 'handler' => RecordingWatchHandler::class, 'throw' => true]]);

        Event::fake([MonitorWentDown::class]);
        $this->spy(ExceptionHandler::class);

        $this->artisan('system-status:watch payments')->assertFailed();

        Event::assertDispatched(MonitorWentDown::class);
    }

    public function test_watches_can_be_forced_and_cleared(): void
    {
        $this->artisan('system-status:force payments down --minutes=30')
            ->expectsOutputToContain('Forced the payments watch down from its next run, for 30 minutes.')
            ->assertSuccessful();

        $status = $this->store()->get('payments');

        $this->assertSame(WatchState::Down, $status->forced);
        $this->assertEqualsWithDelta(time() + 1800, $status->forcedUntil?->getTimestamp(), 5);

        $this->artisan('system-status:watch payments')
            ->expectsOutputToContain('DOWN went down')
            ->expectsOutputToContain('Forced down, so not checked')
            ->assertSuccessful();

        $this->travel(31)->minutes();

        $this->assertNull($this->store()->get('payments')->forced);

        $this->artisan('system-status:force payments UP')
            ->expectsOutputToContain('Forced the payments watch up from its next run, until cleared.')
            ->assertSuccessful();

        $this->assertNull($this->store()->get('payments')->forcedUntil);

        $this->artisan('system-status:force payments clear')
            ->expectsOutputToContain('Cleared the override on the payments watch')
            ->assertSuccessful();

        $this->assertNull($this->store()->get('payments')->forced);
    }

    public function test_force_validates_its_input(): void
    {
        $this->artisan('system-status:force nope down')->expectsOutputToContain('No watch named "nope" is defined')->assertExitCode(2);
        $this->artisan('system-status:force payments sideways')->expectsOutputToContain('The state must be up, down or clear')->assertExitCode(2);

        foreach (['0', '-5', '1.5', 'soon'] as $minutes) {
            $this->artisan("system-status:force payments down --minutes={$minutes}")
                ->expectsOutputToContain('--minutes must be a whole number of at least 1')
                ->assertExitCode(2);
        }

        $this->artisan('system-status:force payments clear --minutes=5')
            ->expectsOutputToContain('--minutes only applies when forcing a watch up or down')
            ->assertExitCode(2);

        $this->assertNull($this->store()->get('payments')->forced);
    }

    public function test_each_watch_is_scheduled_on_one_server_without_overlapping(): void
    {
        $events = $this->scheduled();

        $this->assertCount(2, $events);
        $this->assertStringContainsString("system-status:watch 'payments'", $events[0]->command);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertStringContainsString("system-status:watch 'search'", $events[1]->command);
        $this->assertSame('* * * * *', $events[1]->expression);
    }

    public function test_nothing_is_scheduled_without_definitions_or_when_turned_off(): void
    {
        $this->rebootWith(['system-status.watches.schedule' => false]);

        $this->assertSame([], $this->scheduled());

        $this->rebootWith(['system-status.watches.schedule' => true]);
        $this->watches([]);

        $this->assertSame([], $this->scheduled());
    }

    public function test_an_invalid_cron_is_reported_when_scheduling(): void
    {
        $this->watches(['payments' => ['monitor' => 'file', 'cron' => 'often']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The payments watch is misconfigured: cron must be a valid cron expression');

        $this->scheduled();
    }

    public function test_the_status_shows_watches_without_running_them(): void
    {
        $this->monitors(['connections' => [['type' => 'file', 'path' => __FILE__, 'name' => 'Deployed']]]);

        static::$healthy = false;
        $this->artisan('system-status:watch payments');
        $this->artisan('system-status:watch payments');
        $this->artisan('system-status:force payments up --minutes=10');

        $response = $this->getJson('/monitors/status?token=status-token')
            ->assertOk()
            ->assertJsonPath('status', 'pass')
            ->assertJsonPath('watches.payments.state', 'down')
            ->assertJsonPath('watches.payments.failures', 2)
            ->assertJsonPath('watches.payments.passes', 0)
            ->assertJsonPath('watches.payments.forced', 'up')
            ->assertJsonPath('watches.search.state', 'up')
            ->assertJsonPath('watches.search.checked_at', null)
            ->assertJsonPath('watches.search.forced', null);

        $this->assertIsString($response->json('watches.payments.checked_at'));
        $this->assertIsString($response->json('watches.payments.forced_until'));
        $this->assertCount(2, RecordingWatchHandler::$calls);
    }

    public function test_watches_never_affect_the_ping(): void
    {
        $this->monitors(['connections' => [['type' => 'file', 'path' => __FILE__]]]);

        static::$healthy = false;
        $this->artisan('system-status:watch payments');
        $this->artisan('system-status:watch payments');

        $this->assertSame(WatchState::Down, $this->store()->get('payments')->state);
        $this->get('/monitors/ping')->assertOk()->assertSee('OK');
    }

    public function test_the_status_survives_an_unreadable_watch_store(): void
    {
        $this->rebootWith(['system-status.watches.cache_store' => 'missing']);

        $this->getJson('/monitors/status?token=status-token')
            ->assertOk()
            ->assertJsonPath('watches.payments.error', 'The watch state could not be read');

        $this->dashboard()->assertOk()->assertSee('Its state could not be read');
    }

    public function test_the_status_omits_watches_when_none_are_defined(): void
    {
        $this->watches([]);

        $this->getJson('/monitors/status?token=status-token')->assertOk()->assertJsonMissingPath('watches');
        $this->dashboard()->assertOk()->assertDontSee('data-watches', false);
    }

    public function test_the_dashboard_shows_watches(): void
    {
        $this->monitors(['connections' => [['type' => 'file', 'path' => __FILE__, 'name' => 'Deployed']]]);

        $this->dashboard()
            ->assertSeeInOrder(['Watches', '0 of 2 up', 'Payments', 'Not checked yet', 'Search', 'Not checked yet']);

        static::$healthy = false;
        $this->artisan('system-status:watch');
        $this->artisan('system-status:watch payments');
        $this->artisan('system-status:force payments up');

        $this->dashboard()
            ->assertOk()
            ->assertSee('All systems operational')
            ->assertSee('data-watches', false)
            ->assertSeeInOrder(['Watches', '1 down', 'Payments', '2 consecutive failures', 'Forced up', 'until cleared', 'Down', 'Search', '1 consecutive pass', 'Up']);

        $this->artisan('system-status:force payments down --minutes=15');

        $this->dashboard()->assertSeeInOrder(['Forced down', 'until', 'data-local-time'], false);
    }

    protected function watches(array $definitions): void
    {
        $this->rebootWith(['system-status.watches.definitions' => $definitions]);
    }

    protected function store(): WatchStore
    {
        return $this->app->make(WatchStore::class);
    }

    /**
     * @return list<ScheduledEvent>
     */
    protected function scheduled(): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (ScheduledEvent $event) => str_contains((string) $event->command, 'system-status:watch'),
        ));
    }

    protected function dashboard(): TestResponse
    {
        return $this->get('/monitors/dashboard', ['X-Status-Token' => 'status-token']);
    }
}
