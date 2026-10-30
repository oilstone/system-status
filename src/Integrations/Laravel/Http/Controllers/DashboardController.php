<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oilstone\SystemStatus\Exceptions\UnknownMonitor;
use Oilstone\SystemStatus\Integrations\Laravel\Http\StatusQuery;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchRegistry;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchSchedule;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Report;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\WatchStatus;
use Throwable;

/**
 * The watches and monitors as a page for people, with the same access rules and options as the JSON status.
 *
 * With watches defined, the page leads with their stored state and only runs monitors when asked to, through
 * run(). Without, it checks every monitor as it loads.
 */
class DashboardController
{
    /**
     * The colour the view gives each state and rating.
     */
    public const TONES = [
        'operational' => 'green', 'degraded' => 'amber', 'failing' => 'red', 'empty' => 'grey', 'invalid' => 'grey',
        'optimal' => 'green', 'acceptable' => 'teal', 'failed' => 'red',
        'up' => 'green', 'down' => 'red', 'overdue' => 'amber', 'pending' => 'grey', 'unknown' => 'grey',
    ];

    protected const STATES = ['operational' => 'Operational', 'degraded' => 'Degraded', 'failing' => 'Failing'];

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
        // Watches lead the page when there are any, and monitors then only run when asked to
        $onDemand = $watches->names() !== [];
        $failAt = Rating::Failed;
        $definitions = [];
        $results = [];
        $error = null;

        try {
            $query = StatusQuery::fromRequest($request, $settings);
            $failAt = $query->failAt;
            $definitions = $status->definitions($query->categories);
            $results = $onDemand ? [] : $this->checkAll($status, $definitions);
        } catch (InvalidArgumentException $e) {
            // A malformed query or an unknown category
            $error = $e->getMessage();
        }

        $watchSummary = $this->summariseWatches($watches, $status);
        $monitorSummary = $this->summariseMonitors($status, $definitions, $results, $failAt, $settings, $watchSummary['links']);

        $html = $this->view->make('system-status::dashboard', [
            'title' => $dashboard['title'] ?? $this->config->get('app.name', 'Application') . ' status',
            'refresh' => $refresh = max(0, (int) ($dashboard['refresh_seconds'] ?? 30)),
            // Monitors that only run when asked can be kept live, re-running at the refresh interval
            'liveSeconds' => $onDemand ? ($refresh ?: 30) : null,
            'runUrl' => route('system-status.dashboard.run', [], false),
            'onDemand' => $onDemand,
            'exposeErrors' => (bool) ($settings['expose_errors'] ?? true),
            'error' => $error,
            'tones' => static::TONES,
            ...($onDemand ? $this->headlineFromWatches($watchSummary, $monitorSummary) : $this->headlineFromReport($results, $error, $failAt, $definitions)),
            ...$watchSummary,
            ...$monitorSummary,
        ])->render();

        // A bad query leaves the watches to show, but the request was still wrong
        return $this->respond($html, $error === null ? 200 : 422);
    }

    /**
     * Runs the monitor named by ?monitor=, the monitor of the watch named by ?watch=, or every monitor in the
     * requested categories, and returns their rows and results for the page to put in place. It never changes a
     * watch's state.
     */
    public function run(Request $request, SystemStatus $status, WatchRegistry $watches): Response
    {
        $settings = $this->config->get('system-status.status', []);
        $monitor = $request->query('monitor', '');
        $watch = $request->query('watch', '');

        try {
            $query = StatusQuery::fromRequest($request, $settings);

            if (! is_string($monitor) || ! is_string($watch)) {
                throw new InvalidArgumentException('monitor and watch must each name one monitor or watch');
            }

            if ($watch !== '' && ! $watches->has($watch)) {
                return $this->respond(sprintf('No watch named "%s" is defined', $watch), 404, 'text/plain');
            }

            // A watch of a status monitor is checked as that monitor, so its row shows the result too
            $monitor = $watch === '' ? $monitor : (string) $watches->monitorId($watch, $status);

            $results = match (true) {
                $monitor !== '' => [$monitor => $status->checkMonitor($monitor)],
                $watch !== '' => ['watch:' . $watch => $status->run($watches->get($watch)->monitor, 'watches')],
                default => $this->checkAll($status, $status->definitions($query->categories)),
            };
        } catch (UnknownMonitor $e) {
            return $this->respond($e->getMessage(), 404, 'text/plain');
        } catch (InvalidArgumentException $e) {
            return $this->respond($e->getMessage(), 422, 'text/plain');
        }

        $links = $this->watchLinks($watches, $status, $watches->statuses());
        $items = [];

        foreach ($results as $id => $result) {
            $id = (string) $id;
            $described = $this->describeResult($result, $query->failAt, $settings);

            $items[] = [
                'slot' => $id,
                'result' => $described,
                'monitor' => $monitor === '' && $watch !== '' ? null : $this->monitorItem($id, $result->name, $described, $links[$id] ?? []),
            ];
        }

        return $this->respond($this->view->make('system-status::run', [
            'items' => $items,
            'tones' => static::TONES,
            'onDemand' => $watches->names() !== [],
        ])->render());
    }

    protected function respond(string $body, int $status = 200, string $type = 'text/html'): Response
    {
        return new Response($body, $status, [
            'Content-Type' => $type . '; charset=UTF-8',
            'Cache-Control' => 'no-store',
            // The page can be opened with ?token=, which must not leak through referrers or search engines
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * @param array<string, array<int|string, mixed>> $definitions
     * @return array<string, Result> Keyed by monitor id
     */
    protected function checkAll(SystemStatus $status, array $definitions): array
    {
        $results = [];

        foreach ($definitions as $category => $monitors) {
            foreach ($monitors as $key => $definition) {
                $results[$category . '.' . $key] = $status->run($definition, (string) $category);
            }
        }

        return $results;
    }

    /**
     * The headline and figures when the page checks its monitors as it loads.
     *
     * @param array<string, Result> $results
     * @param array<string, array<int|string, mixed>> $definitions
     */
    protected function headlineFromReport(array $results, ?string $error, Rating $failAt, array $definitions): array
    {
        $report = new Report(array_values($results));
        $state = match (true) {
            $error !== null => 'invalid',
            $results === [] => 'empty',
            default => $this->stateOf(array_map(fn (Result $result) => $this->resultState($result, $failAt), $results)) ?? 'operational',
        };
        $checks = count($results);

        return [
            'state' => $state,
            'headline' => static::HEADLINES[$state],
            'summary' => match ($state) {
                'invalid' => $error,
                'empty' => 'There is nothing to check yet.',
                default => sprintf(
                    '%d %s across %d %s%s',
                    $checks,
                    Str::plural('check', $checks),
                    count($definitions),
                    Str::plural('category', count($definitions)),
                    $failAt === Rating::Failed ? '' : ', counting ' . $failAt->label() . ' or worse as failing',
                ),
            },
            'stats' => $checks === 0 ? [] : [
                ['label' => 'Checks', 'value' => (string) $checks],
                ['label' => 'Failing', 'value' => (string) count($report->failures($failAt)), 'alert' => $report->failures($failAt) !== []],
                ['label' => 'Slowest check', 'value' => $this->duration(max([0.0, ...array_map(fn (Result $result) => $result->duration, $results)]))],
                ['label' => 'Checked', 'time' => $report->checkedAt(), 'format' => 'clock'],
            ],
        ];
    }

    /**
     * The headline and figures when the watches lead the page.
     */
    protected function headlineFromWatches(array $watches, array $monitors): array
    {
        $counts = $watches['watchCounts'];
        $named = function (string $state) use ($watches, $counts): string {
            $matching = array_values(array_filter($watches['watches'], fn (array $watch) => $watch['condition'] === $state));

            return count($matching) === 1 ? $matching[0]['label'] . ' is ' . $state : $counts[$state] . ' watches are ' . $state;
        };

        [$state, $headline] = match (true) {
            $counts['down'] > 0 => ['down', $named('down')],
            $counts['overdue'] > 0 => ['overdue', $named('overdue')],
            $counts['unknown'] > 0 => ['unknown', 'Watch state couldn’t be read'],
            $counts['up'] === $counts['total'] => ['up', 'All watches up'],
            $counts['up'] === 0 => ['pending', 'Watches haven’t run yet'],
            default => ['pending', $counts['up'] . ' of ' . $counts['total'] . ' watches up'],
        };

        $total = array_sum(array_map(fn (array $category) => count($category['monitors']), $monitors['categories']));

        return [
            'state' => $state,
            'headline' => $headline,
            'summary' => $state === 'overdue'
                ? 'Their state may be out of date. Is the scheduler running?'
                : sprintf('%d %s · %d %s to run when needed', $counts['total'], Str::plural('watch', $counts['total']), $total, Str::plural('monitor', $total)),
            'stats' => [
                ['label' => 'Up', 'value' => (string) $counts['up']],
                ['label' => 'Down', 'value' => (string) $counts['down'], 'alert' => $counts['down'] > 0],
                ['label' => 'Overdue', 'value' => (string) $counts['overdue'], 'alert' => $counts['overdue'] > 0],
                ['label' => 'Last watch run', 'time' => $counts['last_checked_at'], 'format' => 'ago'],
            ],
        ];
    }

    /**
     * Each category's monitors, with any results and the watches that check them.
     *
     * @param array<string, array<int|string, mixed>> $definitions
     * @param array<string, Result> $results Keyed by monitor id
     * @param array<string, list<array>> $links Watches keyed by the id of the monitor they check
     */
    protected function summariseMonitors(SystemStatus $status, array $definitions, array $results, Rating $failAt, array $settings, array $links): array
    {
        $categories = [];
        $states = [];

        foreach ($definitions as $category => $monitors) {
            $items = [];

            foreach ($monitors as $key => $definition) {
                $id = $category . '.' . $key;
                $result = isset($results[$id]) ? $this->describeResult($results[$id], $failAt, $settings) : null;
                $items[] = $this->monitorItem($id, $results[$id]->name ?? $status->factory()->nameFor($definition), $result, $links[$id] ?? []);
                $states[] = $result['state'] ?? null;
            }

            $state = $this->stateOf(array_column(array_filter(array_column($items, 'result')), 'state'));

            $categories[$category] = [
                'label' => Str::headline($category),
                'anchor' => 'category-' . $category,
                'monitors' => $items,
                'state' => $state,
                // Closed unless something in it needs looking at, or it is all there is
                'open' => count($definitions) === 1
                    || in_array($state, ['failing', 'degraded'], true)
                    || in_array('down', array_column(array_merge(...array_column($items, 'watches')), 'state'), true),
            ];
        }

        $ran = array_filter($states);
        $failing = count(array_keys($ran, 'failing', true));
        $degraded = count(array_keys($ran, 'degraded', true));

        return [
            'categories' => $categories,
            'monitorSummary' => match (true) {
                $failing > 0 => ['state' => 'failing', 'label' => $failing . ' failing'],
                $degraded > 0 => ['state' => 'degraded', 'label' => $degraded . ' degraded'],
                $ran !== [] && count($ran) === count($states) => ['state' => 'operational', 'label' => 'All operational'],
                $ran !== [] => ['state' => 'empty', 'label' => count($ran) . ' of ' . count($states) . ' run'],
                default => ['state' => 'empty', 'label' => count($states) . ' ' . Str::plural('monitor', count($states))],
            },
        ];
    }

    /**
     * @param array|null $result See describeResult()
     * @param list<array> $watches The watches that check this monitor
     */
    protected function monitorItem(string $id, string $name, ?array $result, array $watches): array
    {
        return [
            'id' => $id,
            'anchor' => 'monitor-' . $id,
            'name' => $name,
            'result' => $result,
            'watches' => $watches,
            'run_query' => http_build_query(['monitor' => $id]),
        ];
    }

    /**
     * A result as the view shows it.
     */
    protected function describeResult(Result $result, Rating $failAt, array $settings): array
    {
        return [
            'state' => $this->resultState($result, $failAt),
            'rating' => $result->rating->label(),
            'rating_label' => ucfirst($result->rating->label()),
            'duration' => $this->duration($result->duration),
            'error' => $result->error === null ? null : (($settings['expose_errors'] ?? true) ? $result->errorMessage() : 'Check failed'),
            'notes' => $result->notes,
            'meta' => array_map(
                fn (mixed $value) => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $result->meta,
            ),
            'checked_at' => Date::now()->toDateTimeImmutable(),
        ];
    }

    /**
     * Each watch as it was left by its last run, and when it runs. Watches never change the status's pass/fail.
     */
    protected function summariseWatches(WatchRegistry $registry, SystemStatus $status): array
    {
        $statuses = $registry->statuses();
        $now = Date::now();
        $watches = [];
        $counts = ['up' => 0, 'down' => 0, 'overdue' => 0, 'pending' => 0, 'unknown' => 0, 'total' => count($statuses), 'last_checked_at' => null];
        $monitors = [];

        foreach ($status->definitions() as $category => $definitions) {
            foreach ($definitions as $key => $definition) {
                $monitors[$category . '.' . $key] = Str::headline($category) . ' › ' . $status->factory()->nameFor($definition);
            }
        }

        foreach ($statuses as $name => $watch) {
            $state = $this->watchState($watch);

            try {
                $schedule = $registry->schedule($name, $watch, $now);
                $scheduleLabel = $schedule === null ? 'Not scheduled' : $this->describeSchedule($schedule);
            } catch (Throwable) {
                $schedule = null;
                $scheduleLabel = 'Invalid schedule';
            }

            // What the watch's dot and the headline make of it: a missed run matters more than an up state
            $condition = $schedule?->overdueSince !== null && $state !== 'down' ? 'overdue' : $state;
            $counts[$condition]++;

            if ($watch?->checkedAt !== null && ($counts['last_checked_at'] === null || $watch->checkedAt > $counts['last_checked_at'])) {
                $counts['last_checked_at'] = $watch->checkedAt;
            }

            $monitorId = $registry->monitorId($name, $status);

            $watches[] = [
                'name' => $name,
                'anchor' => 'watch-' . $name,
                'label' => Str::headline($name),
                'state' => $state,
                'condition' => $condition,
                'state_label' => $this->watchStateLabel($state),
                'detail' => match (true) {
                    $watch === null => 'Its state could not be read',
                    $watch->failures > 0 => sprintf('%d consecutive %s', $watch->failures, Str::plural('failure', $watch->failures)),
                    $watch->passes > 0 => sprintf('%d consecutive %s', $watch->passes, Str::plural('pass', $watch->passes)),
                    default => null,
                },
                'forced' => $watch?->forced?->value,
                'forced_until' => $watch?->forcedUntil,
                'checked_at' => $watch?->checkedAt,
                'schedule' => $scheduleLabel,
                'cron' => $schedule?->cron,
                'next_run_at' => $schedule?->nextRunAt,
                'overdue_since' => $schedule?->overdueSince,
                'error' => $watch?->error,
                // Only worth saying when the rating, rather than an error, is why the check didn't pass
                'last_check' => $watch?->rating !== null && $watch->error === null && $watch->rating->isAtLeast(Rating::Degraded)
                    ? ['rating' => $watch->rating->label(), 'duration' => $watch->duration === null ? null : $this->duration($watch->duration)]
                    : null,
                'monitor' => $monitorId === null ? null : ['id' => $monitorId, 'anchor' => 'monitor-' . $monitorId, 'label' => $monitors[$monitorId] ?? $monitorId],
                // Checked as its status monitor when it has one, so the result shows on both
                'check_query' => http_build_query(['watch' => $name]),
                'check_slot' => $monitorId ?? 'watch:' . $name,
            ];
        }

        return [
            'watches' => $watches,
            'watchCounts' => $counts,
            'links' => $this->watchLinks($registry, $status, $statuses),
            'watchSummary' => match (true) {
                $counts['down'] > 0 => ['state' => 'down', 'label' => $counts['down'] . ' down'],
                $counts['overdue'] > 0 => ['state' => 'overdue', 'label' => $counts['overdue'] . ' overdue'],
                $counts['up'] === $counts['total'] => ['state' => 'up', 'label' => 'All up'],
                default => ['state' => 'unknown', 'label' => $counts['up'] . ' of ' . $counts['total'] . ' up'],
            },
        ];
    }

    /**
     * The watches that check each status monitor, keyed by the monitor's id.
     *
     * @param array<string, WatchStatus|null> $statuses
     * @return array<string, list<array{name: string, anchor: string, label: string, state: string, state_label: string}>>
     */
    protected function watchLinks(WatchRegistry $registry, SystemStatus $status, array $statuses): array
    {
        $links = [];

        foreach ($statuses as $name => $watch) {
            if (($id = $registry->monitorId($name, $status)) !== null) {
                $state = $this->watchState($watch);

                $links[$id][] = [
                    'name' => $name,
                    'anchor' => 'watch-' . $name,
                    'label' => Str::headline($name),
                    'state' => $state,
                    'state_label' => $this->watchStateLabel($state),
                ];
            }
        }

        return $links;
    }

    protected function watchState(?WatchStatus $watch): string
    {
        return match (true) {
            $watch === null => 'unknown',
            $watch->checkedAt === null => 'pending',
            default => $watch->state->value,
        };
    }

    protected function watchStateLabel(string $state): string
    {
        return ['up' => 'Up', 'down' => 'Down', 'pending' => 'Not checked yet', 'unknown' => 'Unknown'][$state];
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

    protected function resultState(Result $result, Rating $failAt): string
    {
        return match (true) {
            ! $result->passes($failAt) => 'failing',
            $result->rating->isAtLeast(Rating::Degraded) => 'degraded',
            default => 'operational',
        };
    }

    /**
     * The worst of some result states, or null when there are none.
     *
     * @param array<array-key, string|null> $states
     */
    protected function stateOf(array $states): ?string
    {
        return match (true) {
            in_array('failing', $states, true) => 'failing',
            in_array('degraded', $states, true) => 'degraded',
            in_array('operational', $states, true) => 'operational',
            default => null,
        };
    }

    protected function duration(float $ms): string
    {
        return $ms < 1000
            ? number_format($ms, $ms < 10 ? 1 : 0) . ' ms'
            : number_format($ms / 1000, 2) . ' s';
    }
}
