<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oilstone\SystemStatus\Integrations\Laravel\Http\StatusQuery;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchRegistry;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchSchedule;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Report;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\WatchState;
use Oilstone\SystemStatus\WatchStatus;
use Throwable;

/**
 * The status report as a page for people, with the same access rules and options as the JSON status.
 */
class DashboardController
{
    protected const DAYS = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'];

    protected const HEADLINES = [
        'operational' => 'All systems operational',
        'degraded' => 'Some systems are degraded',
        'failing' => 'Some systems are failing',
        'empty' => 'No monitors configured',
        'invalid' => 'The status could not be checked',
    ];

    public function __construct(protected Repository $config, protected ViewFactory $view)
    {
    }

    public function __invoke(Request $request, SystemStatus $status, WatchRegistry $watches): Response
    {
        $settings = $this->config->get('system-status.status', []);
        $dashboard = $this->config->get('system-status.dashboard', []);
        $report = null;
        $error = null;
        $failAt = Rating::Failed;

        try {
            $query = StatusQuery::fromRequest($request, $settings);
            $failAt = $query->failAt;
            $report = $status->check($query->categories);
        } catch (InvalidArgumentException $e) {
            // A malformed query or an unknown category
            $error = $e->getMessage();
        }

        $html = $this->view->make('system-status::dashboard', [
            'title' => $dashboard['title'] ?? $this->config->get('app.name', 'Application') . ' status',
            'refresh' => max(0, (int) ($dashboard['refresh_seconds'] ?? 30)),
            'exposeErrors' => (bool) ($settings['expose_errors'] ?? true),
            'error' => $error,
            'failAt' => $failAt,
            ...$this->summarise($report, $failAt),
            ...$this->summariseWatches($watches),
        ])->render();

        return new Response($html, $error === null ? 200 : 422, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            // The page can be opened with ?token=, which must not leak through referrers or search engines
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Everything the view shows about a report, worked out here so published views stay simple.
     */
    protected function summarise(?Report $report, Rating $failAt): array
    {
        $categories = [];

        foreach ($report ?? [] as $result) {
            $categories[$result->category]['results'][] = $result;
        }

        foreach ($categories as $name => $category) {
            $results = $category['results'];

            $categories[$name] += [
                'label' => Str::headline($name),
                'state' => $this->state($results, $failAt),
            ];
        }

        $results = $report?->results() ?? [];
        $state = $report === null ? 'invalid' : ($results === [] ? 'empty' : $this->state($results, $failAt));

        return [
            'report' => $report,
            'state' => $state,
            'headline' => static::HEADLINES[$state],
            'categories' => $categories,
            'failures' => $report === null ? 0 : count($report->failures($failAt)),
            'slowest' => max([0.0, ...array_map(fn (Result $result) => $result->duration, $results)]),
        ];
    }

    /**
     * Each watch as it was left by its last run, and when it runs. Watches never change the headline or pass/fail.
     */
    protected function summariseWatches(WatchRegistry $registry): array
    {
        $statuses = $registry->statuses();
        $now = Date::now();
        $watches = [];
        $overdue = 0;

        foreach ($statuses as $name => $status) {
            $state = match (true) {
                $status === null => 'unknown',
                $status->checkedAt === null => 'pending',
                default => $status->state->value,
            };

            try {
                $schedule = $registry->schedule($name, $status, $now);
                $scheduleLabel = $schedule === null ? 'Not scheduled' : $this->describeSchedule($schedule);
            } catch (Throwable) {
                $schedule = null;
                $scheduleLabel = 'Invalid schedule';
            }

            $overdue += $schedule?->overdueSince === null ? 0 : 1;

            $watches[] = [
                'name' => $name,
                'label' => Str::headline($name),
                'state' => $state,
                'state_label' => ['up' => 'Up', 'down' => 'Down', 'pending' => 'Not checked yet', 'unknown' => 'Unknown'][$state],
                'detail' => match (true) {
                    $status === null => 'Its state could not be read',
                    $status->failures > 0 => sprintf('%d consecutive %s', $status->failures, Str::plural('failure', $status->failures)),
                    $status->passes > 0 => sprintf('%d consecutive %s', $status->passes, Str::plural('pass', $status->passes)),
                    default => null,
                },
                'forced' => $status?->forced?->value,
                'forced_until' => $status?->forcedUntil,
                'checked_at' => $status?->checkedAt,
                'schedule' => $scheduleLabel,
                'cron' => $schedule?->cron,
                'next_run_at' => $schedule?->nextRunAt,
                'overdue_since' => $schedule?->overdueSince,
                'error' => $status?->error,
                // Only worth saying when the rating, rather than an error, is why the check didn't pass
                'last_check' => $status?->rating !== null && $status->error === null && $status->rating->isAtLeast(Rating::Degraded)
                    ? ['rating' => $status->rating->label(), 'duration' => $status->duration]
                    : null,
            ];
        }

        $down = count(array_filter($statuses, fn (?WatchStatus $status) => $status?->checkedAt !== null && $status->state === WatchState::Down));
        $up = count(array_filter($statuses, fn (?WatchStatus $status) => $status?->checkedAt !== null && $status->state === WatchState::Up));

        return [
            'watches' => $watches,
            'watchSummary' => match (true) {
                $down > 0 => ['state' => 'down', 'label' => $down . ' down'],
                $overdue > 0 => ['state' => 'overdue', 'label' => $overdue . ' overdue'],
                $up === count($statuses) => ['state' => 'up', 'label' => 'All up'],
                default => ['state' => 'unknown', 'label' => $up . ' of ' . count($statuses) . ' up'],
            },
        ];
    }

    /**
     * The common schedules in words, e.g. "Every 5 minutes" or "Daily at 02:00 UTC"; others as the cron expression.
     */
    protected function describeSchedule(WatchSchedule $schedule): string
    {
        $fields = preg_split('/\s+/', $schedule->cron) ?: [];

        if (count($fields) !== 5 || $fields[2] !== '*' || $fields[3] !== '*') {
            return $schedule->cron;
        }

        [$minute, $hour, , , $weekday] = $fields;

        if ($hour === '*' && $weekday === '*') {
            $every = str_starts_with($minute, '*/') ? substr($minute, 2) : '';

            return match (true) {
                $minute === '*' || $every === '1' => 'Every minute',
                ctype_digit($every) => sprintf('Every %d minutes', $every),
                $minute === '0' => 'Every hour',
                ctype_digit($minute) => sprintf('Every hour at :%02d', $minute),
                default => $schedule->cron,
            };
        }

        if (! ctype_digit($minute) || ! ctype_digit($hour)) {
            return $schedule->cron;
        }

        $at = sprintf('at %02d:%02d %s', $hour, $minute, $schedule->timezone);

        return match (true) {
            $weekday === '*' => 'Daily ' . $at,
            $weekday === '1-5' => 'Weekdays ' . $at,
            ctype_digit($weekday) && (int) $weekday <= 7 => static::DAYS[(int) $weekday] . ' ' . $at,
            default => $schedule->cron,
        };
    }

    /**
     * @param list<Result> $results
     */
    protected function state(array $results, Rating $failAt): string
    {
        $failing = array_filter($results, fn (Result $result) => ! $result->passes($failAt));
        $degraded = array_filter($results, fn (Result $result) => $result->rating->isAtLeast(Rating::Degraded));

        return match (true) {
            $failing !== [] => 'failing',
            $degraded !== [] => 'degraded',
            default => 'operational',
        };
    }
}
