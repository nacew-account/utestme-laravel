<?php

namespace Nacew\Utestme\Http;

use Closure;
use Illuminate\Http\Request;
use Nacew\Utestme\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware: remembers the request (so an error knows where it
 * happened), then, once the response has gone to the visitor, counts it and
 * sends what the request collected.
 */
class CountRequests
{
    public function __construct(private Client $client)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->client->startRequest($request->getMethod(), $request->getPathInfo());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->client->countResponse($response->getStatusCode());
        $this->client->flush();
    }
}
