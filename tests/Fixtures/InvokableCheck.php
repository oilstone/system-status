<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use Oilstone\SystemStatus\Probe;

class InvokableCheck
{
    public static int $calls = 0;

    public static bool $passes = true;

    public static function reset(): void
    {
        static::$calls = 0;
        static::$passes = true;
    }

    public function __invoke(Probe $probe, array $config): bool
    {
        static::$calls++;

        $probe->meta('config', $config);

        return static::$passes;
    }
}
