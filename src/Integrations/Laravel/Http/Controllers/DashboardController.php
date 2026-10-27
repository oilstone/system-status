<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oilstone\SystemStatus\Integrations\Laravel\Http\StatusQuery;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Report;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;

/**
 * The status report as a page for people, with the same access rules and options as the JSON status.
 */
class DashboardController
{
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

    public function __invoke(Request $request, SystemStatus $status): Response
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
