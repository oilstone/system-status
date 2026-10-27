<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Oilstone\SystemStatus\Rating;

/**
 * The categories and fail_at rating a status request asks for, falling back to config.
 */
final class StatusQuery
{
    /**
     * @param list<string>|string|null $categories All categories when null
     */
    public function __construct(
        public readonly array|string|null $categories,
        public readonly Rating $failAt,
    ) {
    }

    /**
     * @param array{categories?: list<string>|string|null, fail_at?: Rating|int|string} $settings
     *
     * @throws InvalidArgumentException When the query is malformed
     */
    public static function fromRequest(Request $request, array $settings): self
    {
        // Query values can arrive as arrays (?fail_at[]=x), so check their type before using them
        $failAtQuery = $request->query('fail_at');
        $categoriesQuery = $request->query('categories');
        $failAt = is_string($failAtQuery) ? Rating::tryParse($failAtQuery) : null;

        if (filled($failAtQuery) && $failAt === null) {
            throw new InvalidArgumentException('fail_at must be one of optimal, acceptable, degraded or failed');
        }

        if (filled($categoriesQuery) && ! is_string($categoriesQuery)) {
            throw new InvalidArgumentException('categories must be a comma separated list of category names');
        }

        return new self(
            is_string($categoriesQuery) && filled($categoriesQuery)
                ? explode(',', $categoriesQuery)
                : $settings['categories'] ?? null,
            $failAt ?? Rating::parse($settings['fail_at'] ?? Rating::Failed),
        );
    }
}
