<?php

namespace Oilstone\SystemStatus\Tests\Fixtures;

use Illuminate\Contracts\Queue\Factory;

class FakeQueueFactory implements Factory
{
    public function __construct(protected object $connection)
    {
    }

    public function connection($name = null)
    {
        return $this->connection;
    }
}
