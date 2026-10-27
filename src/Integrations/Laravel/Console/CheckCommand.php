<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\SystemStatus\Exceptions\UnknownCategory;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;

class CheckCommand extends Command
{
    protected const COLOURS = [
        'optimal' => 'green',
        'acceptable' => 'green',
        'degraded' => 'yellow',
        'failed' => 'red',
    ];

    protected $signature = 'system-status:check
        {categories?* : Only check these categories}
        {--fail-at=failed : Rating at which a monitor fails the command (optimal, acceptable, degraded or failed)}';

    protected $description = 'Run the system status monitors and exit non-zero if any fail';

    public function handle(SystemStatus $status): int
    {
        $failAt = Rating::tryParse($this->option('fail-at'));

        if ($failAt === null) {
            $this->components->error('--fail-at must be one of optimal, acceptable, degraded or failed');

            return self::INVALID;
        }

        try {
            $report = $status->check($this->argument('categories') ?: null);
        } catch (UnknownCategory $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $this->table(['Category', 'Monitor', 'Rating', 'Time', 'Detail'], array_map(fn (Result $result) => [
            $result->category,
            $result->name,
            sprintf('<fg=%s>%s</>', static::COLOURS[$result->rating->label()], $result->rating->label()),
            sprintf('%.1fms', $result->duration),
            implode('; ', array_filter([$result->errorMessage(), ...$result->notes])),
        ], $report->results()));

        return $report->passes($failAt) ? self::SUCCESS : self::FAILURE;
    }
}
