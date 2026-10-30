<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Watches;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Oilstone\SystemStatus\Contracts\WatchHandler;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Oilstone\SystemStatus\Integrations\Laravel\Events\MonitorRecovered;
use Oilstone\SystemStatus\Integrations\Laravel\Events\MonitorWentDown;
use Oilstone\SystemStatus\MonitorFactory;
use Oilstone\SystemStatus\Watch;
use Oilstone\SystemStatus\Watcher;
use Oilstone\SystemStatus\WatchOutcome;
use Oilstone\SystemStatus\WatchStore;

/**
 * Builds handlers through the container, and dispatches an event when a watch goes down or recovers.
 */
class ContainerWatcher extends Watcher
{
    public function __construct(
        MonitorFactory $factory,
        WatchStore $store,
        protected Container $container,
        protected Dispatcher $events,
    ) {
        parent::__construct($factory, $store);
    }

    public function run(Watch $watch): WatchOutcome
    {
        try {
            $outcome = parent::run($watch);
        } catch (WatchHandlerFailed $e) {
            // The state was saved, so the transition happened whatever the handler did
            $this->dispatch($e->outcome);

            throw $e;
        }

        $this->dispatch($outcome);

        return $outcome;
    }

    protected function makeHandler(string $class): WatchHandler
    {
        return $this->container->make($class);
    }

    protected function dispatch(WatchOutcome $outcome): void
    {
        if ($outcome->wentDown()) {
            $this->events->dispatch(new MonitorWentDown($outcome));
        } elseif ($outcome->recovered()) {
            $this->events->dispatch(new MonitorRecovered($outcome));
        }
    }
}
