<?php

namespace App\Shared\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * ProblemDetailsResponse — Genera respuestas de error con estructura unificada
 * { "error": { ... } } para toda la API de GestiaHR (ver docs/08-api.md §4).
 */
final class ProblemDetailsResponse
{
    /**
     * @param  array<int, array{field: string, rule: string, message: string}>  $details
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function make(
        int $status,
        string $code,
        ?string $message = null,
        ?string $requestId = null,
        array $details = [],
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $requestId = $requestId ?: (string) request()->header('X-Request-Id', (string) str()->uuid());

        $resolvedMessage = $message ?: (string) trans("errors.{$code}");
        if ($resolvedMessage === "errors.{$code}") {
            $resolvedMessage = (string) trans('errors.internal_error');
        }

        $payload = [
            'error' => [
                'code' => $code,
                'message' => $resolvedMessage,
                'request_id' => $requestId,
                'details' => $details,
                'meta' => (object) $meta,
            ],
        ];

        return response()->json($payload, $status, array_merge([
            'Content-Type' => 'application/json',
            'X-Request-Id' => $requestId,
        ], $headers));
    }
}
