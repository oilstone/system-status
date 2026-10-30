<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use InvalidArgumentException;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\UnknownMonitorType;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\Tests\Fixtures\FakeMonitor;
use Oilstone\SystemStatus\Tests\Fixtures\RecordingWatchHandler;
use Oilstone\SystemStatus\Watch;
use Oilstone\SystemStatus\Watcher;
use Oilstone\SystemStatus\WatchOutcome;
use Oilstone\SystemStatus\WatchState;
use Oilstone\SystemStatus\WatchStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WatcherTest extends TestCase
{
    protected Repository $cache;

    protected WatchStore $store;

    protected Watcher $watcher;

    /** @var Rating|null The rating the monitor reports; null fails it */
    protected ?Rating $rating = Rating::Optimal;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore());
        $this->store = new WatchStore($this->cache);
        $this->watcher = new Watcher(new SystemStatus(), $this->store);
        RecordingWatchHandler::$calls = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function test_a_watch_starts_up_and_goes_down_after_consecutive_failures(): void
    {
        $watch = $this->watch(['failures' => 3]);

        $this->assertSame(WatchState::Up, $this->store->get('payments')->state);
        $this->assertNull($this->store->get('payments')->checkedAt);

        $this->rating = null;

        $first = $this->watcher->run($watch);

        $this->assertSame(WatchState::Up, $first->state);
        $this->assertSame(1, $first->failures);
        $this->assertSame('Payments refused the connection', $first->result?->errorMessage());

        $this->assertSame(WatchState::Up, $this->watcher->run($watch)->state);

        $third = $this->watcher->run($watch);

        $this->assertSame(WatchState::Down, $third->state);
        $this->assertSame(WatchState::Up, $third->previous);
        $this->assertTrue($third->changed);
        $this->assertTrue($third->wentDown());
        $this->assertSame(3, $third->failures);
        $this->assertSame(0, $third->passes);

        $fourth = $this->watcher->run($watch);

        $this->assertSame(WatchState::Down, $fourth->state);
        $this->assertFalse($fourth->changed);
        $this->assertSame(4, $fourth->failures);

        $stored = $this->store->get('payments');

        $this->assertSame(WatchState::Down, $stored->state);
        $this->assertSame(4, $stored->failures);
        $this->assertSame($fourth->checkedAt->getTimestamp(), $stored->checkedAt?->getTimestamp());
    }

    public function test_a_pass_resets_the_failure_count(): void
    {
        $watch = $this->watch(['failures' => 2]);

        $this->rating = null;
        $this->watcher->run($watch);

        $this->rating = Rating::Optimal;
        $this->assertSame(1, $this->watcher->run($watch)->passes);

        $this->rating = null;
        $outcome = $this->watcher->run($watch);

        $this->assertSame(1, $outcome->failures);
        $this->assertSame(WatchState::Up, $outcome->state);
    }

    public function test_a_down_watch_recovers_after_consecutive_passes(): void
    {
        $watch = $this->watch(['recoveries' => 2]);

        $this->rating = null;
        $this->assertTrue($this->watcher->run($watch)->wentDown());

        $this->rating = Rating::Acceptable;
        $first = $this->watcher->run($watch);

        $this->assertSame(WatchState::Down, $first->state);
        $this->assertSame(1, $first->passes);
        $this->assertSame(0, $first->failures);

        $this->rating = null;
        $this->assertSame(WatchState::Down, $this->watcher->run($watch)->state);

        $this->rating = Rating::Optimal;
        $this->watcher->run($watch);
        $recovered = $this->watcher->run($watch);

        $this->assertSame(WatchState::Up, $recovered->state);
        $this->assertTrue($recovered->recovered());
        $this->assertFalse($recovered->wentDown());
        $this->assertSame(2, $recovered->passes);
    }

    public function test_fail_at_decides_which_ratings_count_as_failed(): void
    {
        $this->rating = Rating::Degraded;

        $this->assertSame(WatchState::Up, $this->watcher->run($this->watch())->state);
        $this->assertSame(WatchState::Down, $this->watcher->run($this->watch(['fail_at' => 'degraded']))->state);
    }

    public function test_slow_checks_are_rated_by_the_monitors_thresholds(): void
    {
        $watch = $this->watch(['monitor' => ['class' => FakeMonitor::class, 'thresholds' => [0 => 'optimal'], 'otherwise' => 'failed']]);

        $outcome = $this->watcher->run($watch);

        $this->assertSame(Rating::Failed, $outcome->result?->rating);
        $this->assertSame(WatchState::Down, $outcome->state);
    }

    public function test_the_last_check_is_kept_to_explain_the_state(): void
    {
        $watch = $this->watch(['failures' => 3]);
        $this->rating = null;

        $this->watcher->run($watch);

        $failed = $this->store->get('payments');

        $this->assertSame(Rating::Failed, $failed->rating);
        $this->assertSame('Payments refused the connection', $failed->error);
        $this->assertIsFloat($failed->duration);
        $this->assertSame(
            ['rating' => 'failed', 'duration_ms' => $failed->duration, 'error' => 'Payments refused the connection'],
            $failed->toArray()['last_check'],
        );
        $this->assertSame('Check failed', $failed->toArray(exposeErrors: false)['last_check']['error']);

        $this->rating = Rating::Degraded;
        $this->watcher->run($watch);

        $this->assertSame(Rating::Degraded, $this->store->get('payments')->rating);
        $this->assertNull($this->store->get('payments')->error);
        $this->assertArrayNotHasKey('error', $this->store->get('payments')->toArray()['last_check']);

        // A forced run doesn't check, so there is no last check to show
        $this->store->force('payments', WatchState::Up);
        $this->watcher->run($watch);

        $this->assertNull($this->store->get('payments')->rating);
        $this->assertNull($this->store->get('payments')->toArray()['last_check']);
    }

    public function test_a_watch_can_check_a_monitor_of_the_status(): void
    {
        $status = (new SystemStatus())->add(['class' => FakeMonitor::class, 'name' => 'Payments API', 'check' => function (Probe $probe) {
            $probe->fail('Payments refused the connection');
        }], 'services', 'payments');
        $watcher = new Watcher($status, $this->store);

        $outcome = $watcher->run(Watch::fromArray('payments', ['monitor' => 'services.payments']));

        $this->assertSame('Payments API', $outcome->monitor->name());
        $this->assertSame(WatchState::Down, $outcome->state);
        $this->assertSame('Payments refused the connection', $this->store->get('payments')->error);
    }

    public function test_a_forced_state_applies_immediately_without_checking(): void
    {
        $checks = 0;
        $watch = $this->watch(['failures' => 3, 'monitor' => ['class' => FakeMonitor::class, 'check' => function () use (&$checks) {
            $checks++;
        }]]);

        $this->watcher->run($watch);
        $this->store->force('payments', WatchState::Down);

        $forced = $this->watcher->run($watch);

        $this->assertSame(1, $checks);
        $this->assertSame(WatchState::Down, $forced->state);
        $this->assertSame(WatchState::Down, $forced->forced);
        $this->assertNull($forced->result);
        $this->assertTrue($forced->wentDown());
        $this->assertSame(1, $forced->failures);
        $this->assertSame(0, $forced->passes);
        $this->assertSame(WatchState::Down, $this->store->get('payments')->forced);

        $this->store->force('payments', WatchState::Up);

        $up = $this->watcher->run($watch);

        $this->assertSame(WatchState::Up, $up->state);
        $this->assertSame(1, $up->passes);
        $this->assertSame(0, $up->failures);

        $this->store->clear('payments');
        $this->rating = null;

        $checked = $this->watcher->run($watch);

        $this->assertNull($checked->forced);
        $this->assertNotNull($checked->result);
        $this->assertSame(2, $checks);
    }

    public function test_a_forced_up_watch_stays_up_while_its_monitor_fails(): void
    {
        $this->rating = null;
        $watch = $this->watch();

        $this->watcher->run($watch);
        $this->store->force('payments', WatchState::Up);

        $this->assertTrue($this->watcher->run($watch)->recovered());
        $this->assertSame(WatchState::Up, $this->watcher->run($watch)->state);
    }

    public function test_an_override_can_expire(): void
    {
        Carbon::setTestNow('2026-10-29 12:00:00');

        $watch = $this->watch();
        $this->store->force('payments', WatchState::Down, 600);

        $status = $this->store->get('payments');

        $this->assertSame(WatchState::Down, $status->forced);
        $this->assertEqualsWithDelta(time() + 600, $status->forcedUntil?->getTimestamp(), 5);
        $this->assertSame(WatchState::Down, $this->watcher->run($watch)->state);

        Carbon::setTestNow('2026-10-29 12:09:59');

        $this->assertSame(WatchState::Down, $this->watcher->run($watch)->forced);

        Carbon::setTestNow('2026-10-29 12:10:01');

        $this->assertNull($this->store->get('payments')->forced);

        $outcome = $this->watcher->run($watch);

        $this->assertNull($outcome->forced);
        $this->assertSame(WatchState::Up, $outcome->state);
        $this->assertTrue($outcome->recovered());
    }

    public function test_overrides_must_last_at_least_a_second(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store->force('payments', WatchState::Down, 0);
    }

    public function test_an_unbuildable_monitor_throws_without_changing_state_or_calling_the_handler(): void
    {
        $this->rating = null;
        $this->watcher->run($this->watch());

        foreach ([['type' => 'nope'], ['class' => FakeMonitor::class, 'thresholds' => 'x']] as $monitor) {
            try {
                $this->watcher->run($this->watch(['monitor' => $monitor, 'handler' => RecordingWatchHandler::class]));
                $this->fail('Expected the monitor not to build');
            } catch (UnknownMonitorType|InvalidMonitorConfiguration) {
            }
        }

        $status = $this->store->get('payments');

        $this->assertSame(WatchState::Down, $status->state);
        $this->assertSame(1, $status->failures);
        $this->assertSame([], RecordingWatchHandler::$calls);
    }

    public function test_the_handler_is_called_on_every_run_with_its_options(): void
    {
        $watch = $this->watch(['handler' => RecordingWatchHandler::class, 'failures' => 2, 'channel' => '#ops', 'pool' => ['a', 'b']]);

        $this->rating = null;
        $this->watcher->run($watch);
        $this->watcher->run($watch);
        $this->watcher->run($watch);

        $outcomes = array_map(fn (array $call) => $call['outcome'], RecordingWatchHandler::$calls);

        $this->assertCount(3, $outcomes);
        $this->assertSame([false, true, false], array_map(fn (WatchOutcome $outcome) => $outcome->changed, $outcomes));
        $this->assertSame(['channel' => '#ops', 'pool' => ['a', 'b']], RecordingWatchHandler::$calls[0]['options']);
        $this->assertSame('payments', $outcomes[0]->watch);
        $this->assertInstanceOf(FakeMonitor::class, $outcomes[0]->monitor);
        $this->assertSame('Payments', $outcomes[0]->monitor->name());
    }

    public function test_a_handler_exception_does_not_roll_back_the_state(): void
    {
        $this->rating = null;

        try {
            $this->watcher->run($this->watch(['handler' => RecordingWatchHandler::class, 'throw' => true]));
            $this->fail('Expected the handler to fail');
        } catch (WatchHandlerFailed $e) {
            $this->assertSame('The payments watch handler failed: Failover API refused the request', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
            $this->assertTrue($e->outcome->wentDown());
        }

        $this->assertSame(WatchState::Down, $this->store->get('payments')->state);

        // Handlers hear about the next run too, so they can retry what failed
        $this->expectException(WatchHandlerFailed::class);

        try {
            $this->watcher->run($this->watch(['handler' => RecordingWatchHandler::class, 'throw' => true]));
        } finally {
            $this->assertCount(2, RecordingWatchHandler::$calls);
            $this->assertFalse(RecordingWatchHandler::$calls[1]['outcome']->changed);
        }
    }

    public function test_watches_are_namespaced_in_the_store(): void
    {
        $this->rating = null;
        $this->watcher->run($this->watch());
        $this->store->force('payments', WatchState::Up);

        $this->assertSame(WatchState::Up, $this->store->get('search')->state);
        $this->assertNull($this->store->get('search')->forced);
        $this->assertSame(['state', 'failures', 'passes', 'checked_at', 'rating', 'duration_ms', 'error'], array_keys($this->cache->get('system-status.watch.payments.state')));
        $this->assertSame(['state' => 'up', 'expires_at' => null], $this->cache->get('system-status.watch.payments.override'));
    }

    public function test_unreadable_stored_state_falls_back_to_up(): void
    {
        $this->cache->forever('system-status.watch.payments.state', ['state' => 'sideways', 'failures' => 9]);
        $this->cache->forever('system-status.watch.payments.override', 'down');

        $status = $this->store->get('payments');

        $this->assertSame(WatchState::Up, $status->state);
        $this->assertSame(0, $status->failures);
        $this->assertNull($status->forced);
    }

    public function test_status_converts_to_an_array(): void
    {
        $this->assertSame(
            ['state' => 'up', 'failures' => 0, 'passes' => 0, 'forced' => null, 'forced_until' => null, 'checked_at' => null, 'last_check' => null, 'failed_run' => null],
            $this->store->get('payments')->toArray(),
        );
    }

    public function test_a_failed_run_is_kept_until_a_run_saves_its_check(): void
    {
        $this->store->save('payments', WatchState::Down, 2, 0, new DateTimeImmutable('@1000'));
        $this->store->fail('payments', 'The payments watch is misconfigured', new DateTimeImmutable('@2000'));

        $status = $this->store->get('payments');

        $this->assertSame(WatchState::Down, $status->state);
        $this->assertSame(2, $status->failures);
        $this->assertSame(1000, $status->checkedAt?->getTimestamp());
        $this->assertSame(2000, $status->failedRunAt?->getTimestamp());
        $this->assertSame('The payments watch is misconfigured', $status->failedRunError);
        $this->assertSame('Run failed', $status->toArray(false)['failed_run']['error']);

        $this->store->save('payments', WatchState::Down, 3, 0, new DateTimeImmutable('@3000'));

        $this->assertNull($this->store->get('payments')->failedRunAt);
        $this->assertNull($this->store->get('payments')->failedRunError);
    }

    public function test_definitions_are_read_into_a_watch(): void
    {
        $watch = Watch::fromArray('payments.eu-west_1', [
            'monitor' => 'file',
            'failures' => '3',
            'recoveries' => 2,
            'fail_at' => 'Degraded',
            'handler' => RecordingWatchHandler::class,
            'channel' => '#ops',
        ]);

        $this->assertSame('payments.eu-west_1', $watch->name);
        $this->assertSame('file', $watch->monitor);
        $this->assertSame(3, $watch->failures);
        $this->assertSame(2, $watch->recoveries);
        $this->assertSame(Rating::Degraded, $watch->failAt);
        $this->assertSame(RecordingWatchHandler::class, $watch->handler);
        $this->assertSame(['channel' => '#ops'], $watch->options);

        $defaults = Watch::fromArray('payments', ['monitor' => ['type' => 'file']]);

        $this->assertSame([1, 1, Rating::Failed, null, []], [$defaults->failures, $defaults->recoveries, $defaults->failAt, $defaults->handler, $defaults->options]);
    }

    /**
     * @return array<string, array{0: string, 1: array, 2: string}>
     */
    public static function invalidDefinitions(): array
    {
        return [
            'name' => ['pay ments', ['monitor' => 'file'], 'names may only contain letters, numbers, dots, dashes and underscores'],
            'missing monitor' => ['payments', [], 'monitor must be a monitor definition array, or a type or class name'],
            'monitor object' => ['payments', ['monitor' => new FakeMonitor()], 'monitor must be a monitor definition array'],
            'zero failures' => ['payments', ['monitor' => 'file', 'failures' => 0], 'failures must be a whole number of at least 1'],
            'fractional recoveries' => ['payments', ['monitor' => 'file', 'recoveries' => 1.5], 'recoveries must be a whole number of at least 1'],
            'text failures' => ['payments', ['monitor' => 'file', 'failures' => 'three'], 'failures must be a whole number of at least 1'],
            'boolean failures' => ['payments', ['monitor' => 'file', 'failures' => true], 'failures must be a whole number of at least 1'],
            'fail_at' => ['payments', ['monitor' => 'file', 'fail_at' => 'bad'], 'fail_at must be one of optimal, acceptable, degraded or failed'],
            'handler' => ['payments', ['monitor' => 'file', 'handler' => FakeMonitor::class], 'handler must be the name of a class implementing'],
        ];
    }

    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_are_rejected(string $name, array $definition, string $message): void
    {
        $this->expectException(InvalidMonitorConfiguration::class);
        $this->expectExceptionMessage($message);

        Watch::fromArray($name, $definition);
    }

    protected function watch(array $definition = []): Watch
    {
        return Watch::fromArray('payments', $definition + ['monitor' => [
            'class' => FakeMonitor::class,
            'name' => 'Payments',
            'check' => function (Probe $probe) {
                $this->rating === null ? $probe->fail('Payments refused the connection') : $probe->rate($this->rating);
            },
        ]]);
    }
}
