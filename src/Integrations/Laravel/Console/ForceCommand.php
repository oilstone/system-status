<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchRegistry;
use Oilstone\SystemStatus\WatchState;
use Oilstone\SystemStatus\WatchStore;

class ForceCommand extends Command
{
    protected $signature = 'system-status:force
        {watch : The watch to override}
        {state : up, down or clear}
        {--minutes= : Clear the override after this many minutes}';

    protected $description = 'Force a watch up or down, ignoring its checks, or clear the override';

    public function handle(WatchRegistry $watches, WatchStore $store): int
    {
        $name = $this->argument('watch');
        $state = $this->argument('state');
        $option = $this->option('minutes');
        $minutes = is_string($option) ? filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;

        if (! is_string($name) || ! $watches->has($name)) {
            $this->components->error(sprintf('No watch named "%s" is defined', is_string($name) ? $name : ''));

            return self::INVALID;
        }

        $state = is_string($state) ? strtolower($state) : null;

        if (! in_array($state, ['up', 'down', 'clear'], true)) {
            $this->components->error('The state must be up, down or clear');

            return self::INVALID;
        }

        if ($minutes === false) {
            $this->components->error('--minutes must be a whole number of at least 1');

            return self::INVALID;
        }

        if ($state === 'clear') {
            if ($minutes !== null) {
                $this->components->error('--minutes only applies when forcing a watch up or down');

                return self::INVALID;
            }

            $store->clear($name);
            $this->components->info(sprintf('Cleared the override on the %s watch; it is checked again from its next run.', $name));

            return self::SUCCESS;
        }

        $store->force($name, WatchState::from($state), $minutes === null ? null : $minutes * 60);

        $this->components->info(sprintf(
            'Forced the %s watch %s from its next run, %s.',
            $name,
            $state,
            $minutes === null ? 'until cleared' : sprintf('for %d %s', $minutes, Str::plural('minute', $minutes)),
        ));

        return self::SUCCESS;
    }
}
