<?php

use App\Modules\Identity\Http\Middleware\EnsureSecondFactor;
use App\Modules\Identity\Http\Middleware\VerifyXsrfToken;
use App\Shared\Commands\EnvironmentCheckCommand;
use App\Shared\Commands\GenerateDataDictionaryCommand;
use App\Shared\Commands\RotateFieldKeysCommand;
use App\Shared\Commands\VerifyDatabaseConventionsCommand;
use App\Shared\Exceptions\AyniException;
use App\Shared\Http\Middleware\EnsureIdempotency;
use App\Shared\Http\Middleware\EnsureRequestId;
use App\Shared\Http\Middleware\ResolveTenantContext;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use App\Shared\Outbox\Commands\DispatchOutboxCommand;
use App\Shared\Permissions\RequirePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        DispatchOutboxCommand::class,
        EnvironmentCheckCommand::class,
        VerifyDatabaseConventionsCommand::class,
        GenerateDataDictionaryCommand::class,
        RotateFieldKeysCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // La API no redirige a una pantalla de ingreso: responde 401 unauthenticated.
        $middleware->redirectGuestsTo(fn () => null);

        // Detrás del proxy del web (Next.js) o de nginx: X-Forwarded-Host define la organización.
        // Solo se confía en los proxies listados en TRUSTED_PROXIES.
        $proxies = env('TRUSTED_PROXIES');
        if (is_string($proxies) && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        $middleware->api(prepend: [
            EnsureRequestId::class,
            ResolveTenantContext::class,
            EnsureIdempotency::class,
        ]);
        $middleware->alias([
            RequirePermission::ALIAS => RequirePermission::class,
        ]);
        $middleware->api(append: [
            VerifyXsrfToken::class,
            EnsureSecondFactor::class,
            'throttle:ayni-api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // AyniException: se renderiza a sí misma con el formato unificado
        $exceptions->render(function (AyniException $e) {
            return $e->render();
        });

        // ValidationException → 422 validation_failed con details por campo
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->expectsJson()) {
                $details = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $msg) {
                        $details[] = ['field' => $field, 'rule' => '', 'message' => $msg];
                    }
                }

                return ProblemDetailsResponse::make(422, 'validation_failed', null, null, $details);
            }
        });

        // AuthenticationException → 401 unauthenticated
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ProblemDetailsResponse::make(401, 'unauthenticated');
            }
        });

        // AuthorizationException → 403 forbidden
        $exceptions->render(function (AuthorizationException $e, $request) {
            if ($request->expectsJson()) {
                return ProblemDetailsResponse::make(403, 'forbidden');
            }
        });

        // ModelNotFoundException → 404 not_found
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->expectsJson()) {
                return ProblemDetailsResponse::make(404, 'not_found');
            }
        });

        // NotFoundHttpException (ruta no existe) → 404 not_found
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->expectsJson()) {
                return ProblemDetailsResponse::make(404, 'not_found');
            }
        });

        // ThrottleRequestsException → 429 rate_limited
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->expectsJson()) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? '60';

                return ProblemDetailsResponse::make(
                    429,
                    'rate_limited',
                    null,
                    null,
                    [],
                    [],
                    ['Retry-After' => (string) $retryAfter],
                );
            }
        });
    })->create();
