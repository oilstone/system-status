<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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

        $passes = $this->remember($settings, fn () => $this->check($status, $settings));

        return new Response($passes ? 'OK' : 'FAIL', $passes ? 200 : 503, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store',
        ]);
    }

    protected function check(SystemStatus $status, array $settings): bool
    {
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

        return $passes;
    }

    /**
     * Serve a recent outcome, so neither load balancers nor anonymous requests can make every hit
     * run the checks. Cached per server, since each server checks its own dependencies. Any cache
     * error falls back to running the checks.
     *
     * @param Closure(): bool $check
     */
    protected function remember(array $settings, Closure $check): bool
    {
        $seconds = (int) ($settings['cache_seconds'] ?? 0);

        if ($seconds <= 0) {
            return $check();
        }

        $key = 'system-status:ping:' . sha1((string) json_encode([
            gethostname(),
            $settings['categories'] ?? null,
            $settings['fail_at'] ?? null,
        ]));

        try {
            $store = $this->container->make(CacheFactory::class)->store($settings['cache_store'] ?? null);
            $cached = $store->get($key);
        } catch (Throwable) {
            return $check();
        }

        if (is_bool($cached)) {
            return $cached;
        }

        $passes = $check();

        try {
            $store->put($key, $passes, $seconds);
        } catch (Throwable) {
            // The outcome is still correct; only the next ping will run the checks again
        }

        return $passes;
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
