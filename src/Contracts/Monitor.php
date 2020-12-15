<?php

namespace Oilstone\SystemStatus\Contracts;

use Throwable;

/**
 * Interface Monitor
 * @package Oilstone\SystemStatus\Contracts
 */
interface Monitor
{
    /**
     * @return int
     */
    public function status(): int;

    /**
     * @return int
     */
    public function score(): int;

    /**
     * @return Throwable|null
     */
    public function error(): ?Throwable;

    /**
     * @return int|null
     */
    public function time(): ?int;

    /**
     * @return string
     */
    public function alias(): string;
}
