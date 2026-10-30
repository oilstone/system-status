<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Illuminate\Support\Facades\Storage;
use Oilstone\SystemStatus\Integrations\Laravel\Facades\SystemStatus as SystemStatusFacade;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Queue;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\Tests\Fixtures\FakeMonitor;
use Oilstone\SystemStatus\Tests\Fixtures\FakeQueueConnection;
use Oilstone\SystemStatus\Tests\Fixtures\FakeQueueFactory;
use Oilstone\SystemStatus\Tests\Fixtures\InjectedCheck;
use Oilstone\SystemStatus\Tests\Fixtures\MonitorWithDependency;

class MonitorsTest extends TestCase
{
    public function test_the_database_monitor_queries_a_connection(): void
    {
        $this->rebootWith(['database.connections.unreachable' => [
            'driver' => 'sqlite',
            'database' => sys_get_temp_dir() . '/missing-dir/db.sqlite',
        ]]);

        $this->assertSame([Rating::Optimal, 'Database (testing)'], $this->summary($this->check('database')));
        $this->assertSame(Rating::Optimal, $this->check(['type' => 'database', 'connection' => 'testing', 'query' => 'select 2'])->rating);

        $failed = $this->check(['type' => 'database', 'connection' => 'unreachable']);

        $this->assertSame([Rating::Failed, 'Database (unreachable)'], $this->summary($failed));
        $this->assertNotNull($failed->errorMessage());
    }

    public function test_the_cache_monitor_uses_a_named_store(): void
    {
        $this->assertSame([Rating::Optimal, 'Cache (array)'], $this->summary($this->check(['type' => 'cache', 'store' => 'array'])));
        $this->assertSame([Rating::Optimal, 'Cache (default)'], $this->summary($this->check('cache')));
        $this->assertSame([Rating::Failed, 'Cache (missing)'], $this->summary($this->check(['type' => 'cache', 'store' => 'missing'])));
    }

    public function test_the_disk_monitor_writes_reads_and_deletes_a_file(): void
    {
        Storage::fake('uploads');

        $this->assertSame([Rating::Optimal, 'Disk (uploads)'], $this->summary($this->check(['type' => 'disk', 'disk' => 'uploads', 'directory' => '/health/'])));
        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertSame([Rating::Failed, 'Disk (missing)'], $this->summary($this->check(['type' => 'disk', 'disk' => 'missing'])));
    }

    public function test_the_queue_monitor_counts_only_waiting_jobs(): void
    {
        $result = $this->queue(new FakeQueueConnection(size: 500, pending: 5), ['max_waiting' => 10, 'connection' => 'redis', 'queue' => 'emails']);

        $this->assertSame([Rating::Optimal, 'Queue (redis:emails)'], $this->summary($result));
        $this->assertSame(['waiting' => 5], $result->meta);
        $this->assertSame(Rating::Optimal, $this->queue(new FakeQueueConnection(pending: 10), ['max_waiting' => 10])->rating);
        $this->assertSame('11 jobs are waiting (limit 10)', $this->queue(new FakeQueueConnection(pending: 11), ['max_waiting' => 10])->errorMessage());

        $degraded = $this->queue(new FakeQueueConnection(pending: 11), ['degrade_waiting' => 10, 'max_waiting' => 20]);

        $this->assertSame(Rating::Degraded, $degraded->rating);
        $this->assertSame(['11 jobs are waiting'], $degraded->notes);
    }

    public function test_the_queue_monitor_checks_the_age_of_the_oldest_job(): void
    {
        $old = $this->queue(new FakeQueueConnection(pending: 1, oldest: time() - 120), ['degrade_age' => 30, 'max_age' => 60]);

        $this->assertSame(Rating::Failed, $old->rating);
        $this->assertMatchesRegularExpression('/^The oldest job has waited 12\d+s \(limit 60\)$/', $old->errorMessage());
        $this->assertGreaterThanOrEqual(120, $old->meta['oldest_age_seconds']);

        $this->assertSame(Rating::Degraded, $this->queue(new FakeQueueConnection(pending: 1, oldest: time() - 45), ['degrade_age' => 30, 'max_age' => 60])->rating);
        $this->assertSame(Rating::Optimal, $this->queue(new FakeQueueConnection(), ['max_age' => 60])->rating);
    }

    public function test_the_queue_monitor_validates_its_limits(): void
    {
        $this->assertSame(
            'Queue (default) is misconfigured: max_waiting must be a number of at least 0',
            $this->queue(new FakeQueueConnection(), ['max_waiting' => 'lots'])->errorMessage(),
        );
    }

    public function test_the_queue_type_uses_the_applications_queues(): void
    {
        $this->rebootWith(['queue.default' => 'sync']);

        $this->assertSame([Rating::Optimal, 'Queue (default)'], $this->summary($this->check('queue')));
    }

    public function test_monitor_classes_and_callbacks_are_built_through_the_container(): void
    {
        $this->assertSame(['dependency' => 'injected'], $this->check(['type' => 'callback', 'callback' => InjectedCheck::class])->meta);
        $this->assertSame(['dependency' => 'injected', 'option' => 'set'], $this->check(['class' => MonitorWithDependency::class, 'option' => 'set'])->meta);
    }

    public function test_monitors_are_loaded_from_config_and_the_facade_can_add_more(): void
    {
        $this->monitors(['connections' => ['database']]);

        SystemStatusFacade::add(new FakeMonitor(['name' => 'Added']), 'connections');
        SystemStatusFacade::extend('fake', FakeMonitor::class);

        $status = $this->app->make('system-status');

        $this->assertSame($status, $this->app->make(SystemStatus::class));
        $this->assertSame(['Database (testing)', 'Added'], array_map(fn (Result $result) => $result->name, $status->check()->results()));
        $this->assertTrue($status->factory()->hasType('fake'));
    }

    /**
     * Check one definition through the application's instance, in a category of its own.
     */
    protected function check(mixed $definition): Result
    {
        $category = uniqid('test-');

        return $this->app->make(SystemStatus::class)->add($definition, $category)->check($category)->results()[0];
    }

    protected function queue(object $connection, array $config): Result
    {
        return (new SystemStatus())->add(fn () => new Queue($config, new FakeQueueFactory($connection)))->check()->results()[0];
    }

    /**
     * @return array{Rating, string}
     */
    protected function summary(Result $result): array
    {
        return [$result->rating, $result->name];
    }
}
