<?php

namespace Oilstone\SystemStatus\Tests\Unit\Monitors;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Oilstone\SystemStatus\Monitors\OAuthToken;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class OAuthTokenTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    public function test_client_credentials_are_posted_in_the_form_body_by_default(): void
    {
        $result = $this->check(['client_id' => 'id', 'client_secret' => 's3cret', 'scope' => 'read', 'token_parameters' => ['audience' => 'api', 'grant_type' => 'ignored']]);

        $this->assertSame(Rating::Optimal, $result->rating);
        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('https://idp.test/token', (string) $this->request()->getUri());
        $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'id', 'client_secret' => 's3cret', 'scope' => 'read', 'audience' => 'api'], $this->form());
        $this->assertSame('', $this->request()->getHeaderLine('Authorization'));
    }

    public function test_basic_client_auth_keeps_credentials_out_of_the_body(): void
    {
        $this->check(['client_id' => 'id', 'client_secret' => 's3cret', 'client_auth' => 'BASIC']);

        $this->assertSame(['grant_type' => 'client_credentials'], $this->form());
        $this->assertSame('Basic ' . base64_encode('id:s3cret'), $this->request()->getHeaderLine('Authorization'));
    }

    public function test_public_clients_can_use_other_grants_without_a_secret(): void
    {
        $this->check(['client_id' => 'id', 'grant_type' => 'password', 'username' => 'user', 'password' => 'pass']);

        $this->assertSame(['grant_type' => 'password', 'client_id' => 'id', 'username' => 'user', 'password' => 'pass'], $this->form());

        $this->check(['client_id' => 'id', 'grant_type' => 'refresh_token', 'refresh_token' => 'r1']);

        $this->assertSame(['grant_type' => 'refresh_token', 'client_id' => 'id', 'refresh_token' => 'r1'], $this->form());
    }

    public function test_the_token_can_be_verified_against_an_endpoint(): void
    {
        $result = $this->check(['client_id' => 'id', 'client_secret' => 's', 'verify_url' => 'https://api.test/me'], [
            new Response(200, [], '{"access_token":"abc"}'),
            new Response(401, [], '{"error_description":"Token expired"}'),
        ]);

        $this->assertSame('Bearer abc', $this->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('GET api.test/me responded with HTTP 401 (Token expired)', $result->errorMessage());
    }

    public function test_token_responses_must_contain_an_access_token(): void
    {
        $this->assertSame('The token response did not include an access token', $this->check(['client_id' => 'id', 'client_secret' => 's'], [new Response(200, [], '{"token_type":"bearer"}')])->errorMessage());
        $this->assertSame('POST idp.test/token responded with HTTP 400 (invalid_client)', $this->check(['client_id' => 'id', 'client_secret' => 's'], [new Response(400, [], '{"error":"invalid_client"}')])->errorMessage());
    }

    public static function misconfigurations(): array
    {
        return [
            'no token url' => [['token_url' => null, 'client_id' => 'id', 'client_secret' => 's'], 'OAuth token is missing required configuration: token_url'],
            'no client id' => [['client_secret' => 's'], 'OAuth token is missing required configuration: client_id'],
            'client credentials without a secret' => [['client_id' => 'id'], 'OAuth token is missing required configuration: client_secret'],
            'password grant without credentials' => [['client_id' => 'id', 'grant_type' => 'password'], 'OAuth token is missing required configuration: username, password'],
            'unknown grant' => [['client_id' => 'id', 'grant_type' => 'implicit'], 'OAuth token is misconfigured: unsupported grant type "implicit"'],
            'unknown client auth' => [['client_id' => 'id', 'client_secret' => 's', 'client_auth' => 'jwt'], 'OAuth token is misconfigured: client_auth must be one of post, basic'],
        ];
    }

    #[DataProvider('misconfigurations')]
    public function test_misconfiguration_fails_before_any_request(array $config, string $message): void
    {
        $result = $this->check($config);

        $this->assertSame($message, $result->errorMessage());
        $this->assertSame([], $this->history);
    }

    public function test_subclasses_can_supply_their_own_token_url(): void
    {
        $monitor = new class(['client_id' => 'id', 'client_secret' => 's', 'options' => ['handler' => $this->handler([new Response(200, [], '{"access_token":"abc"}')])]]) extends OAuthToken
        {
            protected function tokenUrl(): string
            {
                return 'https://vendor.test/oauth/token';
            }
        };

        $this->assertSame(Rating::Optimal, (new SystemStatus())->add($monitor)->check()->results()[0]->rating);
        $this->assertSame('https://vendor.test/oauth/token', (string) $this->request()->getUri());
    }

    /**
     * @param list<Response>|null $responses
     */
    protected function check(array $config, ?array $responses = null): Result
    {
        $config += ['type' => 'oauth_token', 'token_url' => 'https://idp.test/token'];
        $config['options'] = ['handler' => $this->handler($responses ?? [new Response(200, [], '{"access_token":"abc"}')])];

        return (new SystemStatus())->add(array_filter($config, fn (mixed $value) => $value !== null))->check()->results()[0];
    }

    /**
     * @param list<Response> $responses
     */
    protected function handler(array $responses): HandlerStack
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    protected function request(): RequestInterface
    {
        return $this->history[0]['request'];
    }

    protected function form(): array
    {
        parse_str((string) $this->request()->getBody(), $form);

        return $form;
    }
}
