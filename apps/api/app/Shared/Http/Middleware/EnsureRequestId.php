<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->header('X-Request-Id');
        if ($requestId === '') {
            $requestId = (string) str()->uuid();
            $request->headers->set('X-Request-Id', $requestId);
        }

        // Make the request ID available to log processors without coupling
        // them to the HTTP layer (console commands also run processors).
        app()->instance('request.id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
