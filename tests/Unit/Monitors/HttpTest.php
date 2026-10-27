<?php

namespace Oilstone\SystemStatus\Tests\Unit\Monitors;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class HttpTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    public function test_any_2xx_passes_by_default(): void
    {
        $this->assertSame(Rating::Optimal, $this->check(['url' => 'https://api.test/health'], new Response(204))->rating);

        $request = $this->history[0]['request'];

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.test/health', (string) $request->getUri());
        $this->assertSame('oilstone/system-status', $request->getHeaderLine('User-Agent'));
    }

    public function test_other_statuses_fail_with_the_apis_own_reason(): void
    {
        $result = $this->check(['url' => 'https://api.test/health?key=secret'], new Response(503, [], '{"error":{"code":1},"message":"Maintenance"}'));

        $this->assertSame('GET api.test/health responded with HTTP 503 (Maintenance)', $result->errorMessage());
    }

    public function test_expected_statuses_and_methods_can_be_set(): void
    {
        $result = $this->check(['url' => 'https://api.test/health', 'method' => 'head', 'expect_status' => [200, '401']], new Response(401));

        $this->assertSame(Rating::Optimal, $result->rating);
        $this->assertSame('HEAD', $this->history[0]['request']->getMethod());
        $this->assertSame('GET api.test/health responded with HTTP 200', $this->check(['url' => 'https://api.test/health', 'expect_status' => 204], new Response(200))->errorMessage());
    }

    public function test_json_responses_can_be_checked(): void
    {
        $body = '{"status":"ok","checks":{"db":true,"items":[{"id":7}]}}';
        $expect = ['status' => 'ok', 'checks.db' => true, 'checks.items.0.id' => 7];

        $this->assertSame(Rating::Optimal, $this->check(['url' => 'https://api.test', 'expect_json' => $expect], new Response(200, [], $body))->rating);
        $this->assertSame('application/json', $this->history[0]['request']->getHeaderLine('Accept'));

        $this->assertSame('Expected checks.db to be false but got true', $this->check(['url' => 'https://api.test', 'expect_json' => ['checks.db' => false]], new Response(200, [], $body))->errorMessage());
        $this->assertSame('Expected checks.cache to be true but got null', $this->check(['url' => 'https://api.test', 'expect_json' => ['checks.cache' => true]], new Response(200, [], $body))->errorMessage());
        $this->assertSame('GET api.test returned invalid JSON', $this->check(['url' => 'https://api.test', 'expect_json' => ['a' => 1]], new Response(200, [], '<html>'))->errorMessage());
        $this->assertSame('GET api.test did not return a JSON object', $this->check(['url' => 'https://api.test', 'expect_json' => ['a' => 1]], new Response(200, [], '"ok"'))->errorMessage());
    }

    public function test_connection_errors_never_reveal_credentials_in_urls(): void
    {
        $url = 'https://user:password@api.test/health?key=secret#frag';
        $error = new ConnectException('cURL error 7: Failed to connect (see https://curl.se/libcurl/c/libcurl-errors.html) for https://user:***@api.test/health?key=secret', new Request('GET', $url));

        $message = $this->check(['url' => $url], $error)->errorMessage();

        $this->assertSame('GET api.test/health failed: cURL error 7: Failed to connect (see curl.se/libcurl/c/libcurl-errors.html) for api.test/health', $message);
    }

    public function test_relative_urls_drop_their_query_strings_too(): void
    {
        $error = new ConnectException('Connection refused for http://api.test:8080/health?key=secret', new Request('GET', 'http://api.test:8080/health?key=secret'));

        $result = $this->check(['url' => '/health?key=secret', 'options' => ['base_uri' => 'http://api.test:8080']], $error);

        $this->assertSame('GET /health failed: Connection refused for api.test:8080/health', $result->errorMessage());
    }

    protected function check(array $config, Response|\Throwable $response): Result
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));

        $config['options'] = ($config['options'] ?? []) + ['handler' => $stack];

        return (new SystemStatus())->add(['type' => 'http'] + $config)->check()->results()[0];
    }
}
