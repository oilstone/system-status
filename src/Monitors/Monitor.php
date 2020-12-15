<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Exceptions\MonitorFailedException;
use Oilstone\SystemStatus\StatusCode;
use Throwable;

/**
 * Class Monitor
 * @package Oilstone\SystemStatus\Monitors
 */
abstract class Monitor
{
    /**
     * @var array
     */
    protected array $config;

    /**
     * @var string
     */
    protected string $alias = 'Monitor';

    /**
     * @var Throwable
     */
    protected Throwable $error;

    /**
     * @var int
     */
    protected int $time;

    /**
     * Http constructor.
     * @param array $config
     */
    public function __construct(array $config)
    {
        if (isset($config['alias'])) {
            $this->alias = $config['alias'];
        }

        $this->config = $config;
    }

    /**
     * @return int
     */
    public function status(): int
    {
        $this->test();

        if (isset($this->error)) {
            return StatusCode::SYSTEM_OFFLINE;
        }

        return StatusCode::SYSTEM_OKAY;
    }

    /**
     * @return int
     */
    protected function test(): int
    {
        if (isset($this->time)) {
            return $this->time;
        }

        $started = microtime(true);

        try {
            $this->executeAction();
        } catch (Throwable $t) {
            if (!$t instanceof MonitorFailedException) {
                $t = new MonitorFailedException($this->alias . ' encountered an error (' . $t->getMessage() . ')', $t);
            }

            $this->error = $t;
        }

        $this->time = microtime(true) - $started;

        return $this->time;
    }

    /**
     * @return void
     */
    abstract protected function executeAction(): void;

    /**
     * @return int
     */
    public function score(): int
    {
        $this->test();

        if (isset($this->error)) {
            return StatusCode::UNAVAILABLE;
        }

        if (!isset($this->config['expectations']['response_within'])) {
            return StatusCode::ACCEPTABLE;
        }

        foreach ($this->config['expectations']['response_within'] as $threshold => $score) {
            if ($this->time < $threshold) {
                return $score;
            }
        }

        return StatusCode::UNAVAILABLE;
    }

    /**
     * @return Throwable|null
     */
    public function error(): ?Throwable
    {
        $this->test();

        if (!isset($this->error)) {
            return null;
        }

        return $this->error;
    }

    /**
     * @return int|null
     */
    public function time(): ?int
    {
        $this->test();

        if (!isset($this->time)) {
            return null;
        }

        return $this->time;
    }

    /**
     * @return string
     */
    public function alias(): string
    {
        return $this->alias;
    }
}
