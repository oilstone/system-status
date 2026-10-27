<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Oilstone\SystemStatus\SystemStatus add(\Oilstone\SystemStatus\Contracts\Monitor|\Closure|string|array $monitor, string $category = 'default')
 * @method static \Oilstone\SystemStatus\SystemStatus addMany(array $monitors)
 * @method static \Oilstone\SystemStatus\SystemStatus extend(string $type, string|\Closure $resolver)
 * @method static list<string> categories()
 * @method static bool has(string $category)
 * @method static \Oilstone\SystemStatus\Report check(array|string|null $categories = null, \Oilstone\SystemStatus\Rating|null $stopAt = null)
 * @method static bool passes(array|string|null $categories = null, \Oilstone\SystemStatus\Rating $failAt = \Oilstone\SystemStatus\Rating::Failed)
 *
 * @see \Oilstone\SystemStatus\SystemStatus
 */
class SystemStatus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Oilstone\SystemStatus\SystemStatus::class;
    }
}
