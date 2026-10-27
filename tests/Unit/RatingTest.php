<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use Oilstone\SystemStatus\Rating;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

class RatingTest extends TestCase
{
    public static function parseable(): array
    {
        return [
            'case' => [Rating::Degraded, Rating::Degraded],
            'value' => [300, Rating::Degraded],
            'numeric string' => ['300', Rating::Degraded],
            'name' => ['degraded', Rating::Degraded],
            'name in any case' => ['ACCEPTABLE', Rating::Acceptable],
        ];
    }

    #[DataProvider('parseable')]
    public function test_it_parses_cases_values_and_names(Rating|int|string $input, Rating $expected): void
    {
        $this->assertSame($expected, Rating::parse($input));
        $this->assertSame($expected, Rating::tryParse($input));
    }

    public function test_it_rejects_unknown_ratings(): void
    {
        $this->assertNull(Rating::tryParse(null));
        $this->assertNull(Rating::tryParse(''));
        $this->assertNull(Rating::tryParse('healthy'));
        $this->assertNull(Rating::tryParse(250));

        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('"healthy" is not a valid rating');

        Rating::parse('healthy');
    }

    public function test_worst_picks_the_least_healthy_rating(): void
    {
        $this->assertSame(Rating::Optimal, Rating::worst());
        $this->assertSame(Rating::Degraded, Rating::worst(Rating::Acceptable, Rating::Degraded, Rating::Optimal));
        $this->assertSame(Rating::Failed, Rating::worst(Rating::Failed, Rating::Degraded));
    }

    public function test_comparisons_and_labels(): void
    {
        $this->assertTrue(Rating::Degraded->isAtLeast(Rating::Degraded));
        $this->assertTrue(Rating::Failed->isAtLeast(Rating::Degraded));
        $this->assertFalse(Rating::Acceptable->isAtLeast(Rating::Degraded));
        $this->assertSame('acceptable', Rating::Acceptable->label());
    }
}
