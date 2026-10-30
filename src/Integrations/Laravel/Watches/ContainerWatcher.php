<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Watches;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Oilstone\SystemStatus\Contracts\WatchHandler;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Oilstone\SystemStatus\Integrations\Laravel\Events\MonitorRecovered;
use Oilstone\SystemStatus\Integrations\Laravel\Events\MonitorWentDown;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\Watch;
use Oilstone\SystemStatus\Watcher;
use Oilstone\SystemStatus\WatchOutcome;
use Oilstone\SystemStatus\WatchStore;
use Throwable;

/**
 * Builds handlers through the container, and dispatches an event when a watch goes down or recovers. A listener
 * that throws is rethrown as WatchEventFailed, as the run had already saved its state.
 */
class ContainerWatcher extends Watcher
{
    public function __construct(
        SystemStatus $status,
        WatchStore $store,
        protected Container $container,
        protected Dispatcher $events,
    ) {
        parent::__construct($status, $store);
    }

    public function run(Watch $watch): WatchOutcome
    {
        try {
            $outcome = parent::run($watch);
        } catch (WatchHandlerFailed $e) {
            // The state was saved, so the transition happened whatever the handler did
            $this->dispatch($e->outcome, $e);

            throw $e;
        }

        $this->dispatch($outcome);

        return $outcome;
    }

    protected function makeHandler(string $class): WatchHandler
    {
        return $this->container->make($class);
    }

    /**
     * @param WatchHandlerFailed|null $handlerFailure The handler's failure on this run, kept if a listener fails too
     *
     * @throws WatchEventFailed When a listener throws, which, like a handler failing, leaves the saved state standing
     */
    protected function dispatch(WatchOutcome $outcome, ?WatchHandlerFailed $handlerFailure = null): void
    {
        $event = match (true) {
            $outcome->wentDown() => new MonitorWentDown($outcome),
            $outcome->recovered() => new MonitorRecovered($outcome),
            default => null,
        };

        if ($event === null) {
            return;
        }

        try {
            $this->events->dispatch($event);
        } catch (Throwable $e) {
            throw new WatchEventFailed($outcome, $event::class, $e, $handlerFailure);
        }
    }
}
