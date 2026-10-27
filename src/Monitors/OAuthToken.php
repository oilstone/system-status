<?php

namespace Oilstone\SystemStatus\Monitors;

use Oilstone\SystemStatus\Concerns\MakesHttpRequests;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Probe;

/**
 * Requests an OAuth 2 access token and optionally uses it against a verification URL.
 * Extend it to monitor an API behind OAuth: call fetchToken() and use the token in check().
 *
 * Options:
 * - token_url
 * - grant_type: client_credentials (default), password or refresh_token
 * - client_id, client_secret
 * - username, password: for the password grant
 * - refresh_token: for the refresh_token grant
 * - scope
 * - token_parameters: extra form parameters for the token request
 * - verify_url: GET with the token as a bearer; must respond 2xx
 * - timeout, connect_timeout, options: see MakesHttpRequests
 */
class OAuthToken extends Monitor
{
    use MakesHttpRequests;

    protected const GRANT_REQUIREMENTS = [
        'client_credentials' => [],
        'password' => ['username', 'password'],
        'refresh_token' => ['refresh_token'],
    ];

    protected string $name = 'OAuth token';

    protected array $required = ['client_id', 'client_secret'];

    public function check(Probe $probe): void
    {
        $token = $this->fetchToken();

        if (! empty($this->config['verify_url'])) {
            $this->request('GET', $this->config['verify_url'], [
                'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
            ]);
        }
    }

    protected function configure(): void
    {
        if ($this->blank($this->tokenUrl())) {
            throw InvalidMonitorConfiguration::missing($this->name(), ['token_url']);
        }

        $requirements = static::GRANT_REQUIREMENTS[$this->grantType()]
            ?? throw InvalidMonitorConfiguration::invalid($this->name(), sprintf('unsupported grant type "%s"', $this->grantType()));

        $missing = array_values(array_filter($requirements, fn (string $key) => $this->blank($this->config[$key] ?? null)));

        if ($missing) {
            throw InvalidMonitorConfiguration::missing($this->name(), $missing);
        }
    }

    /**
     * @return array{access_token: string}&array<string, mixed>
     */
    protected function fetchToken(): array
    {
        $token = $this->requestJson('POST', $this->tokenUrl(), ['form_params' => $this->tokenParameters()]);

        if (! is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
            $this->fail('The token response did not include an access token');
        }

        return $token;
    }

    protected function tokenUrl(): ?string
    {
        return $this->config['token_url'] ?? null;
    }

    protected function grantType(): string
    {
        return $this->config['grant_type'] ?? 'client_credentials';
    }

    protected function tokenParameters(): array
    {
        $parameters = [
            'grant_type' => $this->grantType(),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ];

        foreach (static::GRANT_REQUIREMENTS[$this->grantType()] as $key) {
            $parameters[$key] = $this->config[$key];
        }

        if (! empty($this->config['scope'])) {
            $parameters['scope'] = $this->config['scope'];
        }

        return $parameters + ($this->config['token_parameters'] ?? []);
    }
}
