<?php

namespace Oilstone\SystemStatus\Contracts;

use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Rating;

interface Monitor
{
    public function name(): string;

    /**
     * Throw to fail the check. Use the probe to degrade the rating or attach detail without failing.
     */
    public function check(Probe $probe): void;

    /**
     * Rate a successful check by how long it took, in milliseconds.
     */
    public function rate(float $duration): Rating;
}
