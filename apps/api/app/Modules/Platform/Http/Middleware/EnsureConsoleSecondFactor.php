<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Application\Console\ConsoleAuth;
use App\Modules\Platform\Infrastructure\Models\PlatformSession;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAS-1 · ADR 0011: en la consola el segundo factor es SIEMPRE obligatorio. Sin superarlo
 * (o configurarlo), ninguna ruta operativa responde: 401 mfa_required con meta.step.
 */
final class EnsureConsoleSecondFactor
{
    public const ALIAS = 'platform.mfa';

    public function __construct(private readonly ConsoleAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('platform');
        $session = $request->attributes->get(PlatformSession::class);
        if (! $user instanceof PlatformUser || ! $session instanceof PlatformSession) {
            return ProblemDetailsResponse::make(401, 'unauthenticated');
        }

        $step = $this->auth->step($user, $session);
        if ($step === 'ok') {
            return $next($request);
        }

        return ProblemDetailsResponse::make(401, 'mfa_required', null, null, [], ['step' => $step, 'methods' => ['totp']]);
    }
}
