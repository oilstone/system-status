<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Oilstone\SystemStatus\Tests\Fixtures\SdkMonitor;
use PHPUnit\Framework\TestCase;

class MakesHttpRequestsTest extends TestCase
{
    public function test_the_sdk_client_shares_the_monitors_timeouts_and_options(): void
    {
        $monitor = new SdkMonitor([
            'timeout' => 10,
            'connect_timeout' => 2,
            'options' => ['headers' => ['X-Api-Key' => 'key'], 'proxy' => 'http://proxy.test', 'http_errors' => false],
        ]);

        $client = $monitor->sdkClient();

        $this->assertSame(10, $client->getConfig('timeout'));
        $this->assertSame(2, $client->getConfig('connect_timeout'));
        $this->assertSame('http://proxy.test', $client->getConfig('proxy'));
        $this->assertSame('key', $client->getConfig('headers')['X-Api-Key']);
        $this->assertTrue($client->getConfig('http_errors'));
        $this->assertFalse($monitor->monitorClient()->getConfig('http_errors'));
        $this->assertNotSame($client, $monitor->sdkClient());

        $defaults = (new SdkMonitor())->sdkClient();

        $this->assertSame(5, $defaults->getConfig('timeout'));
        $this->assertSame(3, $defaults->getConfig('connect_timeout'));
        $this->assertSame('oilstone/system-status', $defaults->getConfig('headers')['User-Agent']);
    }

    public function test_only_the_sdk_client_throws_on_error_responses(): void
    {
        $handler = HandlerStack::create(new MockHandler([new Response(500), new Response(500)]));
        $monitor = new SdkMonitor(['options' => ['handler' => $handler]]);

        $this->assertSame(500, $monitor->monitorClient()->request('GET', 'https://api.test')->getStatusCode());

        $this->expectException(ServerException::class);

        $monitor->sdkClient()->request('GET', 'https://api.test');
    }
}
