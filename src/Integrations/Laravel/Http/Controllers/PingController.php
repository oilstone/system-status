<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Response;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Report;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use Psr\Log\LoggerInterface;
use Throwable;

class PingController
{
    public function __construct(protected Container $container, protected Repository $config)
    {
    }

    public function __invoke(SystemStatus $status): Response
    {
        $settings = $this->config->get('system-status.ping', []);
        $report = null;
        $exception = null;

        try {
            $failAt = Rating::parse($settings['fail_at'] ?? Rating::Failed);
            $report = $status->check($settings['categories'] ?? null, $failAt);
            $passes = $report->passes($failAt);
        } catch (Throwable $e) {
            $exception = $e;
            $passes = false;
        }

        if (! $passes && ($settings['log_failures'] ?? true)) {
            $this->logFailure($report, $exception, $settings);
        }

        return new Response($passes ? 'OK' : 'FAIL', $passes ? 200 : 503, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store',
        ]);
    }

    protected function logFailure(?Report $report, ?Throwable $exception, array $settings): void
    {
        try {
            $failAt = Rating::tryParse($settings['fail_at'] ?? null) ?? Rating::Failed;

            $failures = array_map(fn (Result $result) => [
                'category' => $result->category,
                'name' => $result->name,
                'rating' => $result->rating->label(),
                'error' => $result->errorMessage(),
                'notes' => $result->notes,
            ], $report?->failures($failAt) ?? []);

            $this->logger($settings['log_channel'] ?? null)->warning('System status ping failed', array_filter([
                'failures' => $failures,
                'exception' => $exception?->getMessage(),
            ]));
        } catch (Throwable) {
            // Logging must never change the response a load balancer sees
        }
    }

    protected function logger(?string $channel): LoggerInterface
    {
        $logger = $this->container->make(LoggerInterface::class);

        return $channel && method_exists($logger, 'channel') ? $logger->channel($channel) : $logger;
    }
}
