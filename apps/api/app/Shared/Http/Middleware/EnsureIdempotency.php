<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Http\Responses\ProblemDetailsResponse;
use App\Shared\Idempotency\Models\IdempotencyKey;
use App\Shared\Tenancy\TenancyScope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    /**
     * Procesa la solicitud garantizando idempotencia cuando se envía el encabezado Idempotency-Key.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo aplica a peticiones con efecto (POST, PUT, PATCH, DELETE)
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');

        if (empty($idempotencyKey)) {
            return $next($request);
        }

        $tenantId = TenancyScope::current();
        if (empty($tenantId)) {
            return $next($request);
        }

        $requestHash = hash('sha256', (string) $request->getContent());
        $now = now();

        /** @var IdempotencyKey|null $existing */
        $existing = IdempotencyKey::where('tenant_id', $tenantId)
            ->where('key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            // Caso 1: La petición ya fue completada exitosamente o con respuesta estable
            if ($existing->response_status !== null) {
                // Si el payload difiere, conflicto de idempotencia
                if ($existing->request_hash !== $requestHash) {
                    return ProblemDetailsResponse::make(
                        status: 409,
                        code: 'idempotency_conflict',
                        message: 'La clave de idempotencia ya fue utilizada con un cuerpo de petición diferente.',
                    );
                }

                // Devolver la respuesta cacheada sin duplicar el efecto
                return response()
                    ->json($existing->response_body, $existing->response_status)
                    ->header('Idempotency-Replayed', 'true');
            }

            // Caso 2: Petición en curso con bloqueo activo
            if ($existing->locked_until !== null && $existing->locked_until->isFuture()) {
                return ProblemDetailsResponse::make(
                    status: 409,
                    code: 'request_in_progress',
                    message: 'Una petición con esta clave de idempotencia ya está en procesamiento.',
                );
            }

            // Caso 3: Bloqueo vencido sin respuesta -> renovar bloqueo
            $existing->update([
                'request_hash' => $requestHash,
                'locked_until' => $now->copy()->addSeconds(90),
            ]);
            $record = $existing;
        } else {
            // Caso 4: Primera petición con esta clave -> crear registro bloqueado
            $record = IdempotencyKey::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'key' => $idempotencyKey,
                'request_method' => $request->method(),
                'request_path' => $request->path(),
                'request_hash' => $requestHash,
                'locked_until' => $now->copy()->addSeconds(90),
                'expires_at' => $now->copy()->addDays(1),
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        // Guardar resultado para repeticiones
        $statusCode = $response->getStatusCode();
        $body = null;

        if ($response instanceof JsonResponse) {
            $body = $response->getData(true);
        } else {
            $content = $response->getContent();
            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        $record->update([
            'response_status' => $statusCode,
            'response_body' => $body,
            'locked_until' => null,
        ]);

        return $response;
    }
}
