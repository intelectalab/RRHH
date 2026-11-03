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

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
