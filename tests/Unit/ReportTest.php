<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use DateTimeImmutable;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Report;
use Oilstone\SystemStatus\Result;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReportTest extends TestCase
{
    public function test_pass_or_fail_depends_on_the_fail_at_rating(): void
    {
        $report = new Report([
            new Result('connections', 'Database', Rating::Optimal, 1.0),
            new Result('services', 'API', Rating::Degraded, 2.0),
        ]);

        $this->assertTrue($report->passes());
        $this->assertSame([], $report->failures());
        $this->assertFalse($report->passes(Rating::Degraded));
        $this->assertSame(['API'], array_map(fn (Result $result) => $result->name, $report->failures(Rating::Degraded)));
        $this->assertSame(Rating::Degraded, $report->rating());
        $this->assertSame(3.0, $report->duration());
        $this->assertCount(2, $report);
        $this->assertCount(1, $report->category('services'));
        $this->assertSame($report->results(), iterator_to_array($report));
    }

    public function test_it_serialises_results_by_category(): void
    {
        $checkedAt = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $report = new Report([
            new Result('connections', 'Database', Rating::Optimal, 1.234),
            new Result('services', 'API', Rating::Failed, 2.0, new RuntimeException('Token rejected'), ['Retried'], ['region' => 'eu']),
        ], $checkedAt);

        $this->assertSame([
            'status' => 'fail',
            'rating' => 'failed',
            'checked_at' => '2026-01-02T03:04:05+00:00',
            'duration_ms' => 3.2,
            'categories' => [
                'connections' => [['name' => 'Database', 'rating' => 'optimal', 'duration_ms' => 1.2]],
                'services' => [[
                    'name' => 'API',
                    'rating' => 'failed',
                    'duration_ms' => 2.0,
                    'error' => 'Token rejected',
                    'notes' => ['Retried'],
                    'meta' => ['region' => 'eu'],
                ]],
            ],
        ], $report->toArray());

        $this->assertSame('Check failed', $report->toArray(exposeErrors: false)['categories']['services'][0]['error']);
        $this->assertSame($report->toArray(), $report->jsonSerialize());
    }

    public function test_empty_reports_keep_categories_a_json_object(): void
    {
        $json = json_encode(new Report([]));

        $this->assertStringContainsString('"status":"pass"', $json);
        $this->assertStringContainsString('"categories":{}', $json);
    }

    public function test_errors_without_messages_are_named_by_class(): void
    {
        $result = new Result('default', 'API', Rating::Failed, 0.0, new RuntimeException());

        $this->assertSame(RuntimeException::class, $result->errorMessage());
        $this->assertTrue($result->failed());
        $this->assertFalse($result->passes());
    }
}
