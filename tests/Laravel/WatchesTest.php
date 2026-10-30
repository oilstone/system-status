<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Mockery;
use Oilstone\SystemStatus\Exceptions\UnknownMonitorType;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Oilstone\SystemStatus\Integrations\Laravel\Console\WatchCommand;
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

    public function test_each_scheduled_occurrence_runs_once(): void
    {
        $this->artisan('system-status:watch payments --occurrence=202610301205')->assertSuccessful();

        $this->artisan('system-status:watch --occurrence=202610301205')
            ->expectsOutputToContain('Skipped the payments watch, which has already run for 202610301205')
            ->expectsOutputToContain('Search index')
            ->assertSuccessful();

        $this->assertCount(1, RecordingWatchHandler::$calls);
        $this->assertSame(1, $this->store()->get('payments')->passes);

        $this->artisan('system-status:watch payments --occurrence=202610301206')->assertSuccessful();
        $this->artisan('system-status:watch payments')->assertSuccessful();

        $this->assertCount(3, RecordingWatchHandler::$calls);
        $this->assertTrue(Cache::store('array')->lock('system-status.watch.payments.lock', 60)->get(), 'The lock is released after a skipped run');
    }

    public function test_the_occurrence_must_be_a_minute(): void
    {
        $this->artisan('system-status:watch payments --occurrence=now')
            ->expectsOutputToContain('The occurrence must be a minute in the form YmdHi')
            ->assertExitCode(2);

        $this->assertSame([], RecordingWatchHandler::$calls);
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
        $this->travelTo('2026-10-30 12:05:42');

        $events = $this->scheduled();

        $this->assertCount(2, $events);
        $this->assertStringContainsString("system-status:watch 'payments' --occurrence=202610301205", $events[0]->command);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(WatchCommand::LOCK_SECONDS / 60, $events[0]->expiresAt, 'The overlap mutex expires with the command lock');
        $this->assertTrue($events[0]->runInBackground);
        $this->assertStringContainsString("system-status:watch 'search'", $events[1]->command);
        $this->assertSame('* * * * *', $events[1]->expression);
        $this->assertTrue($events[1]->runInBackground);
    }

    public function test_scheduled_watches_can_run_one_after_another(): void
    {
        $this->rebootWith(['system-status.watches.run_in_background' => false]);

        $events = $this->scheduled();

        $this->assertCount(2, $events);
        $this->assertFalse($events[0]->runInBackground);
        $this->assertFalse($events[1]->runInBackground);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function test_each_scheduled_watch_keeps_its_mutex_name_every_minute(): void
    {
        $this->travelTo('2026-10-30 12:05:42');

        $event = $this->scheduled()[0];

        $this->assertSame('system-status:watch:payments', $event->mutexName());
        $this->assertSame('system-status:watch:search', $this->scheduled()[1]->mutexName());

        $this->travelTo('2026-10-30 12:06:03');
        $this->app->forgetInstance(Schedule::class);

        $later = $this->scheduled()[0];

        $this->assertStringContainsString('--occurrence=202610301206', $later->command);
        $this->assertSame('system-status:watch:payments', $later->mutexName());

        // onOneServer() still claims each minute separately
        $servers = $this->app->make(CacheSchedulingMutex::class);

        $this->assertTrue($servers->create($event, Date::parse('2026-10-30 12:05:00')));
        $this->assertFalse($servers->create($later, Date::parse('2026-10-30 12:05:00')));
        $this->assertTrue($servers->create($later, Date::parse('2026-10-30 12:06:00')));
    }

    public function test_schedule_finish_releases_a_background_run_that_ends_in_a_later_minute(): void
    {
        $this->travelTo('2026-10-30 12:05:42');

        $event = $this->scheduled()[0];

        // As schedule:run does before starting the command in the background
        $this->assertTrue($event->mutex->create($event));
        $this->assertTrue($event->mutex->exists($event));

        $this->travelTo('2026-10-30 12:07:10');
        $this->app->forgetInstance(Schedule::class);

        Event::fake([ScheduledBackgroundTaskFinished::class]);

        $this->artisan('schedule:finish', ['id' => 'system-status:watch:payments', 'code' => 0])->assertSuccessful();

        Event::assertDispatchedTimes(ScheduledBackgroundTaskFinished::class, 1);
        Event::assertDispatched(ScheduledBackgroundTaskFinished::class, fn ($finished) => str_contains((string) $finished->task->command, '--occurrence=202610301207'));
        $this->assertFalse($event->mutex->exists($event), 'The overlap mutex is released');
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

        $response
            ->assertJsonPath('watches.payments.last_check.rating', 'failed')
            ->assertJsonPath('watches.payments.last_check.error', 'Payments API returned false')
            ->assertJsonPath('watches.payments.schedule.cron', '*/5 * * * *')
            ->assertJsonPath('watches.payments.schedule.timezone', 'UTC')
            ->assertJsonPath('watches.payments.schedule.overdue_since', null)
            ->assertJsonPath('watches.search.last_check', null)
            ->assertJsonPath('watches.search.schedule.cron', '* * * * *');

        $this->assertIsString($response->json('watches.payments.schedule.next_run_at'));

        $this->rebootWith(['system-status.status.expose_errors' => false]);
        $this->artisan('system-status:watch payments');

        $this->getJson('/monitors/status?token=status-token')->assertJsonPath('watches.payments.last_check.error', 'Check failed');
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
            ->assertSee('data-state="pending"', false)
            ->assertSeeInOrder(['Watches haven’t run yet', '2 watches · 1 monitor to run when needed', 'Watches', '0 of 2 up', 'Payments', 'Not checked yet', 'Search', 'Not checked yet']);

        static::$healthy = false;
        $this->artisan('system-status:watch');
        $this->artisan('system-status:watch payments');
        $this->artisan('system-status:force payments up');

        $this->dashboard()
            ->assertOk()
            ->assertSee('<title>Payments is down', false)
            ->assertSee('data-state="down"', false)
            ->assertSee('data-watches', false)
            ->assertSeeInOrder(['Payments is down', 'Up', '1', 'Down', '1', 'Overdue', '0', 'Last watch run'])
            ->assertSeeInOrder(['Watches', '1 down', 'Payments', 'Payments API returned false', '2 consecutive failures', 'Forced up', 'until cleared', 'Down', 'Search', '1 consecutive pass', 'Up'])
            // The monitors wait to be run
            ->assertSeeInOrder(['Monitors', '1 monitor', 'Run all', 'Connections', 'Deployed', 'Not run', 'Run now'])
            ->assertSee('data-time-ago', false);

        $this->artisan('system-status:force payments down --minutes=15');

        $this->dashboard()->assertSeeInOrder(['Forced down', 'until', 'data-local-time'], false);
    }

    public function test_the_dashboard_shows_when_watches_run_and_flags_missed_runs(): void
    {
        $this->monitors(['connections' => [['type' => 'file', 'path' => __FILE__, 'name' => 'Deployed']]]);
        $this->travelTo(Date::parse('2026-10-30 09:00:30'));
        $this->store()->save('payments', WatchState::Up, 0, 1, Date::now());
        $this->store()->save('search', WatchState::Up, 0, 1, Date::now());

        $this->dashboard()
            ->assertSeeInOrder(['All up', 'Payments', 'Every 5 minutes', 'next', '2026-10-30T09:05:00+00:00', 'Search', 'Every minute', 'next', '2026-10-30T09:01:00+00:00'], false)
            ->assertDontSee('Overdue since');

        // Search should have run at 09:01, more than five minutes ago; payments at 09:05, which isn't late yet
        $this->travelTo(Date::parse('2026-10-30 09:06:30'));

        $this->dashboard()
            ->assertOk()
            ->assertSeeInOrder(['Search is overdue', 'Their state may be out of date'])
            ->assertSee('data-state="overdue"', false)
            ->assertSeeInOrder(['1 overdue', 'Payments', 'Search', 'Overdue since', '2026-10-30T09:01:00+00:00', 'Is the scheduler running?'], false);

        $this->getJson('/monitors/status?token=status-token')
            ->assertOk()
            ->assertJsonPath('watches.search.schedule.overdue_since', '2026-10-30T09:01:00+00:00')
            ->assertJsonPath('watches.payments.schedule.overdue_since', null)
            ->assertJsonPath('watches.payments.schedule.next_run_at', '2026-10-30T09:10:00+00:00');

        $this->store()->save('search', WatchState::Up, 0, 2, Date::now());

        $this->dashboard()->assertSee('All watches up')->assertDontSee('Overdue since');
    }

    public function test_watches_can_check_a_status_monitor(): void
    {
        $this->monitors(['services' => [
            'payments' => ['type' => 'callback', 'name' => 'Payments API', 'callback' => fn () => static::$healthy],
            ['type' => 'file', 'path' => __FILE__, 'name' => 'Deployed'],
        ]]);
        $this->watches([
            'payments' => ['monitor' => 'services.payments', 'failures' => 2],
            'search' => ['monitor' => ['type' => 'file', 'path' => __FILE__, 'name' => 'Search index']],
        ]);

        static::$healthy = false;
        $this->artisan('system-status:watch payments')->expectsOutputToContain('Payments API: failed');
        $this->artisan('system-status:watch payments');

        $this->assertSame(WatchState::Down, $this->store()->get('payments')->state);

        $this->getJson('/monitors/status?token=status-token')
            ->assertJsonPath('watches.payments.monitor', 'services.payments')
            ->assertJsonPath('watches.search.monitor', null)
            ->assertJsonPath('categories.services.0.name', 'Payments API');

        // Each side links to the other, and the category with a down watch opens
        $this->dashboard()
            ->assertSeeInOrder(['id="watch-payments"', 'checks', 'href="#monitor-services.payments"', 'Services › Payments API'], false)
            ->assertSeeInOrder(['id="category-services"', 'open', 'id="monitor-services.payments"', 'href="#watch-payments"', 'Watched by Payments', 'Down'], false)
            ->assertSeeInOrder(['data-check-slot="services.payments"', 'data-run-key="services.payments"', 'data-check-slot="watch:search"', 'data-run-key="watch:search"'], false);
    }

    public function test_checking_a_watch_from_the_dashboard_runs_its_monitor_without_changing_it(): void
    {
        $this->monitors(['services' => [
            'payments' => ['type' => 'callback', 'name' => 'Payments API', 'callback' => fn () => static::$healthy],
        ]]);
        $this->watches([
            'payments' => ['monitor' => 'services.payments', 'failures' => 2],
            'search' => ['monitor' => ['type' => 'callback', 'name' => 'Search index', 'callback' => fn () => static::$healthy]],
        ]);

        static::$healthy = false;

        // A watch of a status monitor is checked as that monitor, so its row in the list is returned too
        $this->get('/monitors/dashboard/run?watch=payments', ['X-Status-Token' => 'status-token'])
            ->assertOk()
            ->assertSeeInOrder(['data-monitor="services.payments"', 'Watched by Payments', 'Payments API returned false', 'Failed'], false)
            ->assertSeeInOrder(['data-check-for="services.payments"', 'Checked', 'failed', 'Payments API returned false'], false);

        $this->get('/monitors/dashboard/run?watch=search', ['X-Status-Token' => 'status-token'])
            ->assertOk()
            ->assertDontSee('data-monitor=', false)
            ->assertSeeInOrder(['data-check-for="watch:search"', 'failed', 'Search index returned false'], false);

        $this->assertNull($this->store()->get('payments')->checkedAt);
        $this->assertNull($this->store()->get('search')->checkedAt);

        $this->get('/monitors/dashboard/run?watch=nope', ['X-Status-Token' => 'status-token'])
            ->assertNotFound()
            ->assertSee('No watch named "nope" is defined', false);
    }

    public function test_overdue_warnings_can_be_turned_off(): void
    {
        $this->rebootWith(['system-status.watches.overdue_warnings' => false]);
        $this->travelTo(Date::parse('2026-10-30 09:00:30'));
        $this->store()->save('payments', WatchState::Up, 0, 1, Date::now());
        $this->store()->save('search', WatchState::Up, 0, 1, Date::now());
        $this->travelTo(Date::parse('2026-10-30 10:00:00'));

        $this->dashboard()
            ->assertSee('All watches up', false)
            ->assertSeeInOrder(['Search', 'Every minute', 'next', '2026-10-30T10:01:00+00:00'], false)
            ->assertDontSee('Overdue since');

        $this->getJson('/monitors/status?token=status-token')
            ->assertJsonPath('watches.search.schedule.overdue_since', null)
            ->assertJsonPath('watches.search.schedule.next_run_at', '2026-10-30T10:01:00+00:00');
    }

    public function test_the_dashboard_describes_schedules_in_the_schedulers_timezone(): void
    {
        $this->travelTo(Date::parse('2026-10-30 09:00:30'));
        $this->rebootWith(['app.schedule_timezone' => 'America/New_York']);
        $this->watches([
            'hourly' => ['monitor' => 'file', 'cron' => '0 * * * *'],
            'quarter-past' => ['monitor' => 'file', 'cron' => '15 * * * *'],
            'nightly' => ['monitor' => 'file', 'cron' => '@daily'],
            'weekdays' => ['monitor' => 'file', 'cron' => '30 2 * * 1-5'],
            'mondays' => ['monitor' => 'file', 'cron' => '0 9 * * 1'],
            'every-other-hour' => ['monitor' => 'file', 'cron' => '15 */2 * * *'],
            'monthly' => ['monitor' => 'file', 'cron' => '0 0 1 * *'],
            'broken' => ['monitor' => 'file', 'cron' => 'whenever'],
        ]);

        $this->dashboard()
            ->assertOk()
            ->assertSeeInOrder([
                'Hourly', 'Every hour',
                'Quarter Past', 'Every hour at :15',
                'Nightly', 'Daily at 00:00 America/New_York', '2026-10-31T00:00:00-04:00',
                'Weekdays', 'Weekdays at 02:30 America/New_York',
                'Mondays', 'Mondays at 09:00 America/New_York',
                'Every Other Hour', '15 */2 * * *',
                'Monthly', '0 0 1 * *',
                'Broken', 'Invalid schedule',
            ], false);

        $this->getJson('/monitors/status?token=status-token')
            ->assertOk()
            ->assertJsonPath('watches.nightly.schedule.cron', '0 0 * * *')
            ->assertJsonPath('watches.broken.schedule', null);

        $this->rebootWith(['system-status.watches.schedule' => false]);

        $this->dashboard()->assertSee('Not scheduled')->assertDontSee('· next');
        $this->getJson('/monitors/status?token=status-token')->assertJsonPath('watches.hourly.schedule', null);
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
