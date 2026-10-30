<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Oilstone\SystemStatus\Integrations\Laravel\SystemStatusServiceProvider;
use Oilstone\SystemStatus\Probe;
use RuntimeException;

class DashboardTest extends TestCase
{
    protected array $config = [
        'app.name' => 'Acme',
        'system-status.status.token' => 'status-token',
        'system-status.status.public_environments' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitors([
            'connections' => [['type' => 'file', 'path' => __FILE__, 'name' => 'Deployed']],
            'third_party_services' => [['type' => 'callback', 'name' => 'Payments API', 'callback' => function (Probe $probe) {
                $probe->degrade('Responding slowly')->meta('region', 'eu-west-1')->meta('queued', 3);
            }]],
        ]);
    }

    public function test_access_follows_the_status_rules(): void
    {
        $this->get('/monitors/dashboard')->assertForbidden();
        $this->get('/monitors/dashboard?token=wrong')->assertForbidden();
        $this->get('/monitors/dashboard', ['X-Status-Token' => 'status-token'])->assertOk();

        $this->rebootWith(['system-status.status.public_environments' => ['testing']]);

        $this->get('/monitors/dashboard')->assertOk();
    }

    public function test_it_renders_the_report_as_a_page(): void
    {
        $this->dashboard()
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<title>Some systems are degraded · Acme status</title>', false)
            ->assertSee('data-state="degraded"', false)
            ->assertSeeInOrder(['Acme status', 'Some systems are degraded', '2 checks across 2 categories'])
            ->assertSeeInOrder(['Connections', 'Operational', 'Deployed', 'Optimal'])
            ->assertSeeInOrder(['Third Party Services', 'Degraded', 'Payments API', 'Responding slowly', 'region', 'eu-west-1', 'queued', '3']);
    }

    public function test_the_headline_reflects_the_worst_result(): void
    {
        $this->dashboard('?categories=connections')
            ->assertSee('All systems operational')
            ->assertSee('data-state="operational"', false);

        $this->monitors(['connections' => [['type' => 'callback', 'name' => 'Search', 'callback' => fn () => throw new RuntimeException('Connection refused')]]]);

        $this->dashboard()
            ->assertOk()
            ->assertSee('Some systems are failing')
            ->assertSee('is-failing', false)
            ->assertSee('Connection refused');
    }

    public function test_fail_at_is_applied_and_explained(): void
    {
        $this->dashboard('?fail_at=degraded')
            ->assertSee('Some systems are failing')
            ->assertSee('counting degraded or worse as failing');

        $this->rebootWith(['system-status.status.fail_at' => 'degraded']);

        $this->dashboard()->assertSee('Some systems are failing');
    }

    public function test_output_from_monitors_is_escaped(): void
    {
        $this->monitors(['connections' => [[
            'type' => 'callback',
            'name' => '<b>Search</b>',
            'callback' => fn (Probe $probe) => throw new RuntimeException('<script>alert(1)</script>'),
        ]]]);

        $this->dashboard()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;b&gt;Search&lt;/b&gt;', false);
    }

    public function test_errors_can_be_hidden(): void
    {
        $this->monitors(['connections' => [['type' => 'callback', 'name' => 'Search', 'callback' => fn () => throw new RuntimeException('db.internal:5432 refused')]]]);
        $this->rebootWith(['system-status.status.expose_errors' => false]);

        $this->dashboard()
            ->assertSee('Check failed')
            ->assertDontSee('db.internal');
    }

    public function test_invalid_queries_explain_themselves(): void
    {
        $this->dashboard('?categories=nope')
            ->assertStatus(422)
            ->assertSee('The status could not be checked')
            ->assertSee('Unknown monitor categories: nope');

        $this->dashboard('?fail_at[]=degraded')
            ->assertStatus(422)
            ->assertSee('fail_at must be one of optimal, acceptable, degraded or failed');
    }

    public function test_it_explains_when_nothing_is_monitored(): void
    {
        $this->monitors([]);

        $this->dashboard()
            ->assertOk()
            ->assertSee('No monitors configured')
            ->assertSee('config/system-status.php');
    }

    public function test_the_title_and_refresh_interval_can_be_configured(): void
    {
        $this->dashboard()->assertSee('data-refresh="30"', false)->assertSee('Refreshes every 30 seconds');

        $this->rebootWith([
            'system-status.dashboard.title' => 'Platform health',
            'system-status.dashboard.refresh_seconds' => 0,
        ]);

        $this->dashboard()
            ->assertSee('<title>Some systems are degraded · Platform health</title>', false)
            ->assertSee('data-refresh="0"', false)
            ->assertDontSee('Refreshes every');
    }

    public function test_the_route_can_be_disabled_or_given_more_middleware(): void
    {
        $this->rebootWith([
            'system-status.routes.status_middleware' => ['throttle:10,1'],
            'system-status.routes.dashboard_middleware' => ['auth.basic'],
        ]);

        $middleware = Route::getRoutes()->getByName('system-status.dashboard')->gatherMiddleware();

        $this->assertContains('throttle:10,1', $middleware);
        $this->assertContains('auth.basic', $middleware);
        $this->assertNotContains('auth.basic', Route::getRoutes()->getByName('system-status.status')->gatherMiddleware());

        $this->rebootWith(['system-status.dashboard.enabled' => false]);

        $this->dashboard()->assertNotFound();
        $this->assertNotNull(Route::getRoutes()->getByName('system-status.status'));
    }

    public function test_without_watches_the_monitors_run_as_the_page_loads(): void
    {
        $runs = 0;
        $this->monitors(['connections' => [['type' => 'callback', 'name' => 'Search', 'callback' => function () use (&$runs) {
            $runs++;
        }]]]);

        // Running them all again is a refresh, so the headline stays in step; there is nothing to run one at a time
        $this->dashboard()
            ->assertSeeInOrder(['All systems operational', 'Monitors', 'All operational', 'data-refresh-now>Run all', 'Connections', 'Search', 'Optimal'], false)
            ->assertDontSee('Run now')
            ->assertDontSee('Not run');

        $this->assertSame(1, $runs);

        $this->rebootWith(['system-status.watches.definitions' => ['search' => ['monitor' => 'connections.0']]]);
        $this->dashboard()->assertSee('Not run')->assertDontSee('Optimal');

        $this->assertSame(1, $runs);
    }

    public function test_monitors_can_be_made_live_only_when_watches_lead(): void
    {
        // Without watches every monitor runs on each refresh, so there is nothing to make live
        $this->dashboard()->assertDontSee('data-live-seconds="', false)->assertDontSee('data-live="', false);

        $this->rebootWith(['system-status.watches.definitions' => ['deployed' => ['monitor' => 'connections.0']]]);

        $this->dashboard()
            ->assertSee('data-live-seconds="30"', false)
            ->assertSeeInOrder(['Monitors', 'data-live=""', 'Live', 'data-run-key="*">Run all', 'data-live="connections.0"', 'Live', 'data-run-key="connections.0">Run now'], false);

        $this->runFromDashboard('?monitor=connections.0')->assertSee('data-live="connections.0"', false)->assertSee('Run now');

        // Live monitors keep the refresh interval, or run every 30 seconds when the page doesn't refresh
        $this->rebootWith(['system-status.dashboard.refresh_seconds' => 10]);
        $this->dashboard()->assertSee('data-live-seconds="10"', false);

        $this->rebootWith(['system-status.dashboard.refresh_seconds' => 0]);
        $this->dashboard()->assertSee('data-live-seconds="30"', false);
    }

    public function test_each_result_has_a_bar_scaled_to_the_slowest(): void
    {
        $this->monitors(['connections' => [
            ['type' => 'callback', 'name' => 'Fast', 'callback' => fn () => true],
            ['type' => 'callback', 'name' => 'Slow', 'callback' => fn () => usleep(20000)],
        ]]);

        $html = $this->dashboard()->assertSee('data-duration="', false)->getContent();

        $this->assertSame(2, substr_count($html, '<span class="bar" aria-hidden="true">'));
        $this->assertMatchesRegularExpression('/Fast.*?width: \d%.*?Slow.*?width: 100%/s', $html);

        // Results run on demand arrive one at a time, so the page scales them
        $this->rebootWith(['system-status.watches.definitions' => ['fast' => ['monitor' => 'connections.0']]]);

        $this->dashboard()->assertDontSee('<span class="bar"', false);
        $this->runFromDashboard('?monitor=connections.1')
            ->assertSee('data-duration="', false)
            ->assertSee('<span class="bar" aria-hidden="true"><span style="width: 0%"></span></span>', false);
    }

    public function test_healthy_categories_start_closed(): void
    {
        $this->dashboard()
            ->assertSee('<details class="card category" id="category-connections" data-section data-category >', false)
            ->assertSee('<details class="card category" id="category-third_party_services" data-section data-category  open >', false);
    }

    public function test_monitors_can_be_run_one_at_a_time_or_all_together(): void
    {
        $this->runFromDashboard('?monitor=connections.0')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSeeInOrder(['data-monitor="connections.0"', 'data-state="operational"', 'Deployed', 'Optimal'], false)
            ->assertSee('data-check-for="connections.0"', false)
            ->assertDontSee('Payments API');

        $this->runFromDashboard()
            ->assertOk()
            ->assertSeeInOrder(['data-monitor="connections.0"', 'data-monitor="third_party_services.0"', 'data-state="degraded"', 'Responding slowly', 'eu-west-1'], false);

        $this->runFromDashboard('?categories=third_party_services&fail_at=degraded')
            ->assertSee('data-state="failing"', false)
            ->assertDontSee('Deployed');

        $this->get('/monitors/dashboard/run?monitor=connections.0')->assertForbidden();
    }

    public function test_running_explains_bad_requests(): void
    {
        $this->runFromDashboard('?monitor=connections.9')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('No monitor has the id "connections.9"', false);

        $this->runFromDashboard('?monitor[]=connections.0')->assertStatus(422)->assertSee('monitor and watch must each name one monitor or watch');
        $this->runFromDashboard('?categories=nope')->assertStatus(422)->assertSee('Unknown monitor categories: nope');
    }

    public function test_errors_in_run_results_can_be_hidden(): void
    {
        $this->monitors(['connections' => [['type' => 'callback', 'name' => 'Search', 'callback' => fn () => throw new RuntimeException('db.internal:5432 refused')]]]);
        $this->rebootWith(['system-status.status.expose_errors' => false]);

        $this->runFromDashboard()->assertSee('Check failed')->assertDontSee('db.internal');
    }

    public function test_the_views_can_be_published(): void
    {
        $paths = ServiceProvider::pathsToPublish(SystemStatusServiceProvider::class, 'system-status-views');

        $this->assertCount(1, $paths);
        $this->assertFileExists(array_key_first($paths) . '/dashboard.blade.php');
        $this->assertSame(resource_path('views/vendor/system-status'), reset($paths));
    }

    protected function dashboard(string $query = ''): TestResponse
    {
        return $this->get('/monitors/dashboard' . $query, ['X-Status-Token' => 'status-token']);
    }

    protected function runFromDashboard(string $query = ''): TestResponse
    {
        return $this->get('/monitors/dashboard/run' . $query, ['X-Status-Token' => 'status-token']);
    }
}
