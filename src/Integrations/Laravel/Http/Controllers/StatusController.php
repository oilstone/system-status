<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Oilstone\SystemStatus\Integrations\Laravel\Http\StatusQuery;
use Oilstone\SystemStatus\Integrations\Laravel\Watches\WatchRegistry;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\WatchStatus;

class StatusController
{
    public function __construct(protected Repository $config)
    {
    }

    public function __invoke(Request $request, SystemStatus $status, WatchRegistry $watches): JsonResponse
    {
        $settings = $this->config->get('system-status.status', []);
        $headers = ['Cache-Control' => 'no-store'];
        $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        try {
            $query = StatusQuery::fromRequest($request, $settings);
            $report = $status->check($query->categories);
        } catch (InvalidArgumentException $e) {
            // A malformed query or an unknown category
            return new JsonResponse(['error' => $e->getMessage()], 422, $headers, $options);
        }

        $data = $report->toArray($query->failAt, (bool) ($settings['expose_errors'] ?? true));

        // Read from the store as the watches last left them; they never affect the outcome
        if ($statuses = $watches->statuses()) {
            $data['watches'] = array_map(
                fn (?WatchStatus $status) => $status?->toArray() ?? ['error' => 'The watch state could not be read'],
                $statuses,
            );
        }

        return new JsonResponse(
            $data,
            $report->passes($query->failAt) ? 200 : 503,
            $headers,
            $options,
        );
    }
}
