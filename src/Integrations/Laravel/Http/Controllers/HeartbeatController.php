<?php

namespace Oilstone\SystemStatus\Integrations\Laravel\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Confirms the application boots and can serve a request, without checking any dependencies.
 */
class HeartbeatController
{
    public function __invoke(): Response
    {
        return new Response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }
}
