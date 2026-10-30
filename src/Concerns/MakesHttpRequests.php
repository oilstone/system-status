<?php

namespace Oilstone\SystemStatus\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle helpers for monitors. Options read from config:
 * - timeout: seconds for the whole request (default 5)
 * - connect_timeout: seconds to establish a connection (default 3)
 * - options: Guzzle client defaults such as headers, auth, proxy or verify
 *
 * Use http() for requests made by the monitor itself, and sdkHttpClient() for a client to give
 * a third-party SDK.
 */
trait MakesHttpRequests
{
    private ?ClientInterface $httpClient = null;

    abstract protected function fail(string $message, ?Throwable $previous = null): never;

    protected function http(): ClientInterface
    {
        return $this->httpClient ??= $this->makeHttpClient(forSdk: false);
    }

    /**
     * A Guzzle client for third-party SDKs, with the same timeouts and options as http(). Guzzle's
     * http_errors is left at its default, because SDKs rely on the exceptions it throws.
     */
    protected function sdkHttpClient(): Client
    {
        return $this->makeHttpClient(forSdk: true);
    }

    private function makeHttpClient(bool $forSdk): Client
    {
        if (! class_exists(Client::class)) {
            $this->fail('HTTP checks need guzzlehttp/guzzle; run composer require guzzlehttp/guzzle');
        }

        $defaults = [
            'timeout' => $this->config['timeout'] ?? 5,
            'connect_timeout' => $this->config['connect_timeout'] ?? 3,
            'http_errors' => false,
            'headers' => ['User-Agent' => 'oilstone/system-status'],
        ];
        $options = $this->config['options'] ?? [];

        if ($forSdk) {
            unset($defaults['http_errors'], $options['http_errors']);
        }

        return new Client(array_replace($defaults, $options));
    }

    /**
     * @param list<int>|null $expect Accepted status codes; any 2xx when null
     */
    protected function request(string $method, string $url, array $options = [], ?array $expect = null): ResponseInterface
    {
        try {
            $response = $this->http()->request($method, $url, $options);
        } catch (GuzzleException|InvalidArgumentException $e) {
            // Guzzle rejects a malformed URL with a plain InvalidArgumentException that repeats it in full
            $this->fail(sprintf('%s %s failed: %s', $method, $this->describeUrl($url), $this->redactUrls($e->getMessage())), $e);
        }

        $status = $response->getStatusCode();
        $accepted = $expect === null ? $status >= 200 && $status < 300 : in_array($status, $expect, true);

        if (! $accepted) {
            $reason = $this->errorReason((string) $response->getBody());

            $this->fail(sprintf(
                '%s %s responded with HTTP %d%s',
                $method,
                $this->describeUrl($url),
                $status,
                $reason !== null ? " ({$reason})" : '',
            ));
        }

        return $response;
    }

    /**
     * @param list<int>|null $expect Accepted status codes; any 2xx when null
     */
    protected function requestJson(string $method, string $url, array $options = [], ?array $expect = null): array
    {
        $options['headers']['Accept'] ??= 'application/json';

        $response = $this->request($method, $url, $options, $expect);

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail(sprintf('%s %s returned invalid JSON', $method, $this->describeUrl($url)), $e);
        }

        if (! is_array($data)) {
            $this->fail(sprintf('%s %s did not return a JSON object', $method, $this->describeUrl($url)));
        }

        return $data;
    }

    /**
     * Host and path only, so query string credentials never reach a report.
     */
    protected function describeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['host'])) {
            // Relative to a base_uri option, or unparseable, which may still start with a scheme and credentials
            return (string) preg_replace('~^(?:[a-z][a-z0-9+.-]*:)?//(?:[^/?#]*@)?~i', '', substr($url, 0, strcspn($url, '?#')));
        }

        return $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }

    /**
     * Guzzle ends its messages with the full request URL, which can carry credentials.
     */
    protected function redactUrls(string $message): string
    {
        return preg_replace_callback(
            '~\b[a-z][a-z0-9+.-]*://[^\s"\'<>]+~i',
            fn (array $match) => $this->describeUrl($match[0]),
            $message,
        ) ?? $message;
    }

    /**
     * Pull a short explanation out of a JSON error body, covering the common API shapes.
     */
    protected function errorReason(string $body): ?string
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return null;
        }

        $data = array_is_list($data) ? ($data[0] ?? []) : $data;

        foreach (['error_description', 'message', 'description', 'error'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return mb_strimwidth($data[$key], 0, 200, '…');
            }
        }

        return null;
    }
}
