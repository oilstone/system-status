<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Database;
use Oilstone\SystemStatus\Probe;
use RuntimeException;

class StatusEndpointTest extends TestCase
{
    protected array $config = [
        'system-status.status.token' => 'status-token',
        'system-status.status.public_environments' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitors([
            'connections' => [['type' => 'file', 'path' => __FILE__, 'name' => 'Deployed']],
            'services' => [['type' => 'callback', 'name' => 'API', 'callback' => fn (Probe $probe) => $probe->degrade('Slow')]],
        ]);
    }

    public function test_access_is_denied_without_a_token_ip_or_public_environment(): void
    {
        $this->getJson('/monitors/status')->assertForbidden();
        $this->getJson('/monitors/status?token=wrong')->assertForbidden();
    }

    public function test_the_token_can_be_sent_three_ways(): void
    {
        $this->getJson('/monitors/status', ['Authorization' => 'Bearer status-token'])->assertOk();
        $this->getJson('/monitors/status', ['X-Status-Token' => 'status-token'])->assertOk();
        $this->getJson('/monitors/status?token=status-token')->assertOk();
    }

    public function test_an_empty_token_never_grants_access(): void
    {
        $this->rebootWith(['system-status.status.token' => '']);

        $this->getJson('/monitors/status?token=')->assertForbidden();
        $this->getJson('/monitors/status', ['X-Status-Token' => ''])->assertForbidden();
    }

    public function test_allowed_ips_and_ranges_are_granted_access(): void
    {
        $this->rebootWith(['system-status.status.allowed_ips' => ['192.0.2.10', '10.0.0.0/8']]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->getJson('/monitors/status')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->getJson('/monitors/status')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->getJson('/monitors/status')->assertForbidden();
    }

    public function test_public_environments_are_granted_access(): void
    {
        $this->rebootWith(['system-status.status.public_environments' => ['testing']]);

        $this->getJson('/monitors/status')->assertOk();
    }

    public function test_it_reports_every_category(): void
    {
        $response = $this->getJson('/monitors/status?token=status-token')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('status', 'pass')
            ->assertJsonPath('rating', 'degraded')
            ->assertJsonPath('categories.connections.0.name', 'Deployed')
            ->assertJsonPath('categories.services.0.notes', ['Slow']);

        $this->assertIsString($response->json('checked_at'));
    }

    public function test_categories_and_fail_at_can_be_requested(): void
    {
        $this->getJson('/monitors/status?token=status-token&categories=connections')
            ->assertOk()
            ->assertJsonMissingPath('categories.services');

        $this->getJson('/monitors/status?token=status-token&fail_at=degraded')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail');

        $this->getJson('/monitors/status?token=status-token&categories=connections&fail_at=Degraded')->assertOk();
        $this->getJson('/monitors/status?token=status-token&categories=&fail_at=')->assertOk();
    }

    public function test_configured_categories_and_fail_at_are_the_defaults(): void
    {
        $this->rebootWith([
            'system-status.status.categories' => ['services'],
            'system-status.status.fail_at' => 'degraded',
        ]);

        $this->getJson('/monitors/status?token=status-token')
            ->assertStatus(503)
            ->assertJsonMissingPath('categories.connections');
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->getJson('/monitors/status?token=status-token&fail_at=bad')
            ->assertStatus(422)
            ->assertJsonPath('error', 'fail_at must be one of optimal, acceptable, degraded or failed');

        $this->getJson('/monitors/status?token=status-token&fail_at[]=degraded')->assertStatus(422);

        $this->getJson('/monitors/status?token=status-token&categories[]=services')
            ->assertStatus(422)
            ->assertJsonPath('error', 'categories must be a comma separated list of category names');

        $this->getJson('/monitors/status?token=status-token&categories=services,nope')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Unknown monitor categories: nope');
    }

    public function test_failures_respond_503_and_errors_can_be_hidden(): void
    {
        $this->monitors(['connections' => [
            ['type' => 'callback', 'name' => 'Search', 'callback' => fn () => throw new RuntimeException('search.internal refused the connection')],
            ['class' => Database::class, 'thresholds' => 'x'],
        ]]);

        $this->getJson('/monitors/status?token=status-token')
            ->assertStatus(503)
            ->assertJsonPath('categories.connections.0.error', 'search.internal refused the connection')
            ->assertJsonPath('categories.connections.1.name', 'Database');

        $this->rebootWith(['system-status.status.expose_errors' => false]);

        $this->getJson('/monitors/status?token=status-token')
            ->assertStatus(503)
            ->assertJsonPath('categories.connections.0.error', 'Check failed');
    }

    public function test_no_monitors_reports_an_empty_categories_object(): void
    {
        $this->monitors([]);

        $response = $this->getJson('/monitors/status?token=status-token')->assertOk();

        $this->assertStringContainsString('"categories": {}', $response->getContent());
    }
}
