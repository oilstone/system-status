<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Oilstone\SystemStatus\Probe;
use RuntimeException;

class CheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->monitors([
            'connections' => [['type' => 'file', 'path' => __FILE__, 'name' => 'Deployed']],
            'services' => [['type' => 'callback', 'name' => 'API', 'callback' => fn (Probe $probe) => $probe->degrade('Slow')]],
            'broken' => [['type' => 'callback', 'name' => 'Search', 'callback' => fn () => throw new RuntimeException('Refused')]],
        ]);
    }

    public function test_it_prints_results_and_succeeds_when_passing(): void
    {
        $this->artisan('system-status:check connections services')
            ->expectsOutputToContain('Deployed')
            ->expectsOutputToContain('Slow')
            ->assertSuccessful();
    }

    public function test_it_fails_when_any_monitor_fails(): void
    {
        $this->artisan('system-status:check')
            ->expectsOutputToContain('Refused')
            ->assertFailed();
    }

    public function test_fail_at_can_fail_degraded_monitors(): void
    {
        $this->artisan('system-status:check services --fail-at=degraded')->assertFailed();
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->artisan('system-status:check --fail-at=bad')
            ->expectsOutputToContain('--fail-at must be one of optimal, acceptable, degraded or failed')
            ->assertExitCode(2);

        $this->artisan('system-status:check missing')
            ->expectsOutputToContain('Unknown monitor categories: missing')
            ->assertExitCode(2);
    }
}
