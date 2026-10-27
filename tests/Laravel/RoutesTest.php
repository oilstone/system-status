<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Illuminate\Support\Facades\Route;

class RoutesTest extends TestCase
{
    public function test_the_heartbeat_responds_without_checking_anything(): void
    {
        $this->monitors(['connections' => [['type' => 'file', 'path' => '/missing']]]);

        $this->get('/monitors/heartbeat')
            ->assertOk()
            ->assertSeeText('OK')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_routes_are_named_and_skip_the_web_middleware_group(): void
    {
        foreach (['heartbeat', 'ping', 'status'] as $name) {
            $route = Route::getRoutes()->getByName("system-status.{$name}");

            $this->assertNotNull($route);
            $this->assertNotContains('web', $route->gatherMiddleware());
        }
    }

    public function test_the_prefix_and_middleware_can_be_changed(): void
    {
        $this->rebootWith([
            'system-status.routes.prefix' => 'health',
            'system-status.routes.middleware' => ['throttle:60,1'],
            'system-status.routes.status_middleware' => ['auth.basic'],
        ]);

        $this->get('/health/heartbeat')->assertOk();
        $this->get('/monitors/heartbeat')->assertNotFound();

        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('system-status.ping')->gatherMiddleware());
        $this->assertContains('auth.basic', Route::getRoutes()->getByName('system-status.status')->gatherMiddleware());
    }

    public function test_routes_can_be_disabled(): void
    {
        $this->rebootWith(['system-status.routes.enabled' => false]);

        $this->get('/monitors/heartbeat')->assertNotFound();
        $this->assertNull(Route::getRoutes()->getByName('system-status.ping'));
    }
}
