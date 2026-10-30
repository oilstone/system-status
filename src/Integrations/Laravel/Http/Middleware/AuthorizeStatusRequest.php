<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeStatusRequest
{
    public function __construct(protected Application $app, protected Repository $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->authorized($request), 403);

        return $next($request);
    }

    protected function authorized(Request $request): bool
    {
        $settings = $this->config->get('system-status.status', []);

        if ($this->app->environment($settings['public_environments'] ?? [])) {
            return true;
        }

        $token = (string) ($settings['token'] ?? '');

        // Each way is tried, as a proxy or client may send its own Authorization header alongside the status token
        foreach ([$request->bearerToken(), $request->header('X-Status-Token'), $request->query('token')] as $given) {
            if ($token !== '' && is_string($given) && hash_equals($token, $given)) {
                return true;
            }
        }

        $allowedIps = $settings['allowed_ips'] ?? [];

        // Request::ip() is only the client's address when TrustProxies trusts the load balancer
        return $allowedIps !== [] && IpUtils::checkIp((string) $request->ip(), $allowedIps);
    }
}
