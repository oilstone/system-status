<?php

namespace Oilstone\SystemStatus\Integrations\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Integrations\Laravel\Console\CheckCommand;
use Oilstone\SystemStatus\Integrations\Laravel\Console\ForceCommand;
use Oilstone\SystemStatus\Integrations\Laravel\Console\WatchCommand;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers\DashboardController;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers\HeartbeatController;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers\PingController;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers\StatusController;
use Oilstone\SystemStatus\Integrations\Laravel\Http\Middleware\AuthorizeStatusRequest;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\CacheStore;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Database;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Disk;
use Oilstone\SystemStatus\Integrations\Laravel\Monitors\Queue;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\ContainerWatcher;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchRegistry;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\Watcher;
use Oilstone\SystemStatus\WatchStore;

class SystemStatusServiceProvider extends ServiceProvider
{
    protected const CONFIG = __DIR__ . '/config/system-status.php';

    protected const VIEWS = __DIR__ . '/resources/views';

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

        $this->registerWatches();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(static::VIEWS, 'system-status');

        if ($this->app->runningInConsole()) {
            $this->publishes([static::CONFIG => $this->app->configPath('system-status.php')], 'system-status-config');
            $this->publishes([static::VIEWS => $this->app->resourcePath('views/vendor/system-status')], 'system-status-views');

            $this->commands([CheckCommand::class, WatchCommand::class, ForceCommand::class]);

            $this->scheduleWatches();
        }

        $this->registerRoutes();
    }

    protected function registerWatches(): void
    {
        $this->app->singleton(WatchStore::class, function (Container $app): WatchStore {
            return new WatchStore($app->make('cache')->store($app->make('config')->get('system-status.watches.cache_store')));
        });

        $this->app->singleton(WatchRegistry::class, function (Container $app): WatchRegistry {
            $config = $app->make('config');

            return new WatchRegistry(
                $config->get('system-status.watches.definitions', []),
                fn () => $app->make(WatchStore::class),
                (bool) $config->get('system-status.watches.schedule', true),
                // The timezone Laravel gives the schedule
                $config->get('app.schedule_timezone') ?? $config->get('app.timezone'),
                (bool) $config->get('system-status.watches.overdue_warnings', true),
            );
        });

        $this->app->singleton(Watcher::class, function (Container $app): Watcher {
            // Shares the status factory, so watches can use every registered monitor type
            return new ContainerWatcher($app->make(SystemStatus::class), $app->make(WatchStore::class), $app, $app->make('events'));
        });
    }

    protected function scheduleWatches(): void
    {
        $settings = $this->app->make('config')->get('system-status.watches', []);

        if (! ($settings['schedule'] ?? true) || empty($settings['definitions'])) {
            return;
        }

        $background = (bool) ($settings['run_in_background'] ?? true);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($background): void {
            $watches = $this->app->make(WatchRegistry::class);
            // The schedule is built as schedule:run starts, so every server names the same minute. onOneServer()
            // uses the default cache, which may not be shared, so the command also claims the minute in the
            // watch store to make sure only one server runs it.
            $occurrence = Date::now()->format('YmdHi');

            foreach ($watches->names() as $name) {
                // The schedule is built for every artisan command in many apps, so one bad expression must not stop
                // them, or the app's other scheduled tasks; the watch is left out, and the dashboard says why
                try {
                    $cron = $watches->cron($name);
                } catch (InvalidMonitorConfiguration $e) {
                    $this->app->make(ExceptionHandler::class)->report($e);

                    continue;
                }

                // The command changes every minute, so the mutex is named after the watch instead. Overlaps are then
                // prevented across minutes, and schedule:finish finds a background run that ends in a later minute.
                // onOneServer() still applies per minute, as the scheduler appends the minute to this name.
                $event = $schedule->command('system-status:watch', [$name, '--occurrence' => $occurrence])
                    ->cron($cron)
                    ->createMutexNameUsing('system-status:watch:' . $name)
                    ->onOneServer()
                    ->withoutOverlapping((int) ceil(WatchCommand::LOCK_SECONDS / 60));

                if ($background) {
                    $event->runInBackground();
                }
            }
        });
    }

    protected function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make('config');
        $settings = $config->get('system-status.routes', []);

        if (! ($settings['enabled'] ?? true)) {
            return;
        }

        Route::prefix($settings['prefix'] ?? 'monitors')
            ->middleware($settings['middleware'] ?? [])
            ->group(function () use ($settings, $config) {
                Route::get('heartbeat', HeartbeatController::class)->name('system-status.heartbeat');
                Route::get('ping', PingController::class)->name('system-status.ping');
                Route::get('status', StatusController::class)
                    ->middleware([AuthorizeStatusRequest::class, ...($settings['status_middleware'] ?? [])])
                    ->name('system-status.status');

                if ($config->get('system-status.dashboard.enabled', true)) {
                    // The dashboard shows the same report, so it is protected at least as well as the status
                    $middleware = [
                        AuthorizeStatusRequest::class,
                        ...($settings['status_middleware'] ?? []),
                        ...($settings['dashboard_middleware'] ?? []),
                    ];

                    Route::get('dashboard', DashboardController::class)->middleware($middleware)->name('system-status.dashboard');
                    Route::get('dashboard/run', [DashboardController::class, 'run'])->middleware($middleware)->name('system-status.dashboard.run');
                }
            });
    }
}
