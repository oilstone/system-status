<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Concerns\MakesHttpRequests;
use Oilstone\SystemStatus\Probe;

/**
 * Options:
 * - url
 * - method (default GET)
 * - expect_status: accepted status code(s); any 2xx when omitted
 * - expect_json: dot-notation paths mapped to the exact values the JSON response must contain
 * - timeout, connect_timeout, options: see MakesHttpRequests
 */
class Http extends Monitor
{
    use MakesHttpRequests;

    protected string $name = 'HTTP';

    protected array $required = ['url'];

    public function check(Probe $probe): void
    {
        $method = strtoupper($this->config['method'] ?? 'GET');
        $url = (string) $this->config['url'];
        $expect = isset($this->config['expect_status'])
            ? array_values(array_map('intval', (array) $this->config['expect_status']))
            : null;

        if (empty($this->config['expect_json'])) {
            $this->request($method, $url, [], $expect);

            return;
        }

        $data = $this->requestJson($method, $url, [], $expect);

        foreach ($this->config['expect_json'] as $path => $expected) {
            $actual = $this->dataGet($data, (string) $path);

            if ($actual !== $expected) {
                $this->fail(sprintf(
                    'Expected %s to be %s but got %s',
                    $path,
                    json_encode($expected),
                    json_encode($actual),
                ));
            }
        }
    }

    protected function dataGet(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }

            $data = $data[$segment];
        }

        return $data;
    }
}
