<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Str;
use Oilstone\SystemStatus\Exceptions\WatchHandlerFailed;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchRegistry;
use Oilstone\SystemStatus\Watch;
use Oilstone\SystemStatus\Watcher;
use Oilstone\SystemStatus\WatchOutcome;
use Oilstone\SystemStatus\WatchState;
use RuntimeException;
use Throwable;

class WatchCommand extends Command
{
    /**
     * Long enough to outlast any check and handler, short enough that a crashed run soon stops blocking the next.
     */
    protected const LOCK_SECONDS = 300;

    /**
     * How long a claimed occurrence is remembered; well past any clock drift between servers.
     */
    protected const OCCURRENCE_SECONDS = 3600;

    protected $signature = 'system-status:watch
        {watch? : Only run this watch}
        {--occurrence= : The scheduled minute (YmdHi) this run is for, so it runs once across servers}';

    protected $description = 'Run the system status watches, or one of them';

    public function handle(WatchRegistry $watches, Watcher $watcher, CacheFactory $cache, Repository $config, ExceptionHandler $exceptions): int
    {
        $name = $this->argument('watch');

        if (is_string($name) && ! $watches->has($name)) {
            $this->components->error(sprintf('No watch named "%s" is defined', $name));

            return self::INVALID;
        }

        $occurrence = $this->option('occurrence');

        if ($occurrence !== null && (! is_string($occurrence) || preg_match('/^\d{12}$/', $occurrence) !== 1)) {
            $this->components->error('The occurrence must be a minute in the form YmdHi, such as 202601311430');

            return self::INVALID;
        }

        $names = is_string($name) ? [$name] : $watches->names();

        if ($names === []) {
            $this->components->info('No watches are defined.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($names as $name) {
            try {
                $this->runLocked($watches->get($name), $watcher, $cache->store($config->get('system-status.watches.cache_store')), $occurrence);
            } catch (Throwable $e) {
                $failed = true;

                $this->components->error($e->getMessage());
                $exceptions->report($e);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Hold a lock while the watch runs, so a manual run can't race a scheduled one on any server, and run each
     * scheduled occurrence once even when servers don't share the scheduler's cache.
     */
    protected function runLocked(Watch $watch, Watcher $watcher, CacheRepository $cache, ?string $occurrence): void
    {
        $locks = $cache->getStore();

        if (! $locks instanceof LockProvider) {
            throw new RuntimeException('The cache store for watches must support atomic locks; set system-status.watches.cache_store to one that does');
        }

        $lock = $locks->lock('system-status.watch.' . $watch->name . '.lock', static::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->components->warn(sprintf('Skipped the %s watch, which is already running', $watch->name));

            return;
        }

        try {
            if ($occurrence !== null && ! $cache->add('system-status.watch.' . $watch->name . '.ran.' . $occurrence, true, static::OCCURRENCE_SECONDS)) {
                $this->components->info(sprintf('Skipped the %s watch, which has already run for %s', $watch->name, $occurrence));

                return;
            }

            $this->print($watch, $watcher->run($watch));
        } catch (WatchHandlerFailed $e) {
            $this->print($watch, $e->outcome);

            throw $e;
        } finally {
            $this->release($lock);
        }
    }

    protected function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // The lock expires on its own; the run itself is what matters
        }
    }

    protected function print(Watch $watch, WatchOutcome $outcome): void
    {
        $this->components->twoColumnDetail($outcome->watch, sprintf(
            '<fg=%s;options=bold>%s</>%s',
            $outcome->isDown() ? 'red' : 'green',
            strtoupper($outcome->state->value),
            $outcome->wentDown() ? ' went down' : ($outcome->recovered() ? ' recovered' : ''),
        ));

        $result = $outcome->result;

        if ($result === null) {
            $this->line(sprintf('  Forced %s, so not checked', $outcome->forced?->value));
        } else {
            $detail = implode('; ', array_filter([$result->errorMessage(), ...$result->notes]));

            $this->line(sprintf('  %s: %s in %.1fms', $result->name, $result->rating->label(), $result->duration) . ($detail !== '' ? " ({$detail})" : ''));
        }

        $this->line('  ' . $this->progress($watch, $outcome));
    }

    protected function progress(Watch $watch, WatchOutcome $outcome): string
    {
        $failing = $outcome->failures > 0;
        $count = $failing ? $outcome->failures : $outcome->passes;
        $counted = sprintf('%d consecutive %s', $count, Str::plural($failing ? 'failure' : 'pass', $count));

        if ($failing && $outcome->state === WatchState::Up) {
            return sprintf('%s; down at %d', $counted, $watch->failures);
        }

        if (! $failing && $outcome->state === WatchState::Down) {
            return sprintf('%s; up again at %d', $counted, $watch->recoveries);
        }

        return $counted;
    }
}
