<?php

namespace Oilstone\SystemStatus\Integrations\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Oilstone\SystemStatus\Integrations\Laravel\Console\CheckCommand;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers\HeartbeatController;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers\PingController;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers\StatusController;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Middleware\AuthorizeStatusRequest;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\CacheStore;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Database;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Disk;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Queue;
use Oilstone\SystemStatus\SystemStatus;

class SystemStatusServiceProvider extends ServiceProvider
{
    protected const CONFIG = __DIR__ . '/config/system-status.php';

    public function register(): void
    {
        $this->mergeConfigFrom(static::CONFIG, 'system-status');

        $this->app->singleton(SystemStatus::class, function (Container $app): SystemStatus {
            return (new SystemStatus(new ContainerMonitorFactory($app)))
                ->extend('database', Database::class)
                ->extend('cache', CacheStore::class)
                ->extend('disk', Disk::class)
                ->extend('queue', Queue::class)
                ->addMany($app->make('config')->get('system-status.monitors', []));
        });

        $this->app->alias(SystemStatus::class, 'system-status');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([static::CONFIG => $this->app->configPath('system-status.php')], 'system-status-config');

            $this->commands([CheckCommand::class]);
        }

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $settings = $this->app->make('config')->get('system-status.routes', []);

        if (! ($settings['enabled'] ?? true)) {
            return;
        }

        Route::prefix($settings['prefix'] ?? 'monitors')
            ->middleware($settings['middleware'] ?? [])
            ->group(function () use ($settings) {
                Route::get('heartbeat', HeartbeatController::class)->name('system-status.heartbeat');
                Route::get('ping', PingController::class)->name('system-status.ping');
                Route::get('status', StatusController::class)
                    ->middleware([AuthorizeStatusRequest::class, ...($settings['status_middleware'] ?? [])])
                    ->name('system-status.status');
            });
    }
}
