<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Oilstone\SystemStatus\Exceptions\UnknownCategory;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\SystemStatus;

class StatusController
{
    public function __construct(protected Repository $config)
    {
    }

    public function __invoke(Request $request, SystemStatus $status): JsonResponse
    {
        $settings = $this->config->get('system-status.status', []);
        $headers = ['Cache-Control' => 'no-store'];
        $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $failAt = Rating::tryParse($request->query('fail_at'));

        if ($request->filled('fail_at') && $failAt === null) {
            return new JsonResponse(['error' => 'fail_at must be one of optimal, acceptable, degraded or failed'], 422, $headers, $options);
        }

        $failAt ??= Rating::parse($settings['fail_at'] ?? Rating::Failed);
        $categories = $request->filled('categories')
            ? explode(',', (string) $request->query('categories'))
            : $settings['categories'] ?? null;

        try {
            $report = $status->check($categories);
        } catch (UnknownCategory $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422, $headers, $options);
        }

        return new JsonResponse(
            $report->toArray($failAt, (bool) ($settings['expose_errors'] ?? true)),
            $report->passes($failAt) ? 200 : 503,
            $headers,
            $options,
        );
    }
}
