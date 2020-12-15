<?php

namespace Oilstone\SystemStatus\Monitors;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Oilstone\SystemStatus\Contracts\Monitor as MonitorContract;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\MonitorFailedException;

/**
 * Class BearerToken
 * @package Oilstone\SystemStatus\Monitors
 */
class BearerToken extends Monitor implements MonitorContract
{
    /**
     * @var string
     */
    protected string $alias = 'Bearer token monitor';

    /**
     * @var array
     */
    protected array $token;

    /**
     * Redis constructor.
     * @param array $config
     * @throws InvalidMonitorConfiguration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        if (!($this->config['token'] ?? false) && !(($config['grant_type'] ?? false) && ($config['client_id'] ?? false) && ($config['client_secret'] ?? false) && ($config['auth_url'] ?? false))) {
            throw new InvalidMonitorConfiguration('Missing required configuration for ' . $this->alias, $config);
        }

        if ($this->config['token'] ?? false) {
            $this->token = $this->config['token'];
        }
    }

    /**
     * @return void
     * @throws MonitorFailedException
     */
    protected function executeAction(): void
    {
        if (($this->config['grant_type'] ?? false) && ($this->config['client_id'] ?? false) && ($this->config['client_secret'] ?? false) && ($this->config['auth_url'] ?? false)) {
            $this->getToken();
        }

        if ($this->config['verify_url'] ?? false) {
            $this->verifyToken();
        }
    }

    /**
     * @return void
     * @throws MonitorFailedException
     */
    protected function getToken(): void
    {
        $client = new Client($this->config['options'] ?? []);

        $body = [
            'grant_type' => $this->config['grant_type'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ];

        if ($this->config['grant_type'] === 'password') {
            $body['username'] = $this->config['username'];
            $body['password'] = $this->config['password'];
        }

        if ($this->config['scope'] ?? false) {
            $body['scope'] = $this->config['scope'];
        }

        try {
            $response = $client->post($this->config['auth_url'], [
                'form_params' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new MonitorFailedException($this->alias . ' encountered an error (' . $e->getMessage() . ')', $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new MonitorFailedException($this->alias . ' encountered an error (Invalid response status: ' . $response->getStatusCode() . ')');
        }

        try {
            $this->token = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MonitorFailedException($this->alias . ' encountered an error (Failed to decode token request response: ' . $e->getMessage() . ')');
        }

        if (!($this->token['access_token'])) {
            throw new MonitorFailedException($this->alias . ' encountered an error (An invalid access token response was received)');
        }
    }

    /**
     * @return void
     * @throws MonitorFailedException
     */
    protected function verifyToken(): void
    {
        if (!($this->token['access_token'])) {
            throw new MonitorFailedException($this->alias . ' encountered an error (A valid token was not provided for verification)');
        }

        $client = new Client($this->config['options'] ?? []);
        $data = null;

        try {
            $response = $client->get($this->config['verify_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token['access_token'],
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new MonitorFailedException($this->alias . ' encountered an error (' . $e->getMessage() . ')', $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new MonitorFailedException($this->alias . ' encountered an error (Invalid verification response status: ' . $response->getStatusCode() . ')');
        }

        try {
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MonitorFailedException($this->alias . ' encountered an error (Failed to decode verification response: ' . $e->getMessage() . ')');
        }

        if (!$data) {
            throw new MonitorFailedException($this->alias . ' encountered an error (No valid data was received from the verification URL)');
        }
    }
}
