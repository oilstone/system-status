<?php

namespace Oilstone\SystemStatus\Tests\Laravel;

use Oilstone\SystemStatus\Integrations\Laravel\SystemStatusServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Config applied before the package boots, keyed by dot path.
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

    protected function getPackageProviders($app): array
    {
        return [SystemStatusServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('system-status.ping.cache_store', 'array');

        foreach ($this->config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Reboot the application with more config, for settings read while the package boots.
     *
     * @param array<string, mixed> $config
     */
    protected function rebootWith(array $config): void
    {
        $this->config = array_merge($this->config, $config);

        $this->refreshApplication();
    }

    /**
     * @param array<string, list<mixed>> $monitors
     */
    protected function monitors(array $monitors): void
    {
        $this->rebootWith(['system-status.monitors' => $monitors]);
    }
}
