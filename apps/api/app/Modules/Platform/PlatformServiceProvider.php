<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Application\Console\ConsoleSessions;
use App\Modules\Platform\Console\CreatePlatformAdminCommand;
use App\Modules\Platform\Console\ResetPlatformMfaCommand;
use App\Modules\Platform\Http\Middleware\ConsoleBoundary;
use App\Modules\Platform\Http\Middleware\EnsureConsoleSecondFactor;
use App\Modules\Platform\Http\Middleware\RequirePlatformPermission;
use App\Modules\Platform\Infrastructure\Models\PlatformSession;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * SAS-1 · Consola separada del superadministrador: guard, cookie, sesiones, permisos y
 * auditoría propios. Opera sin contexto de organización (regla 1: solo aquí y en Shared/Tenancy).
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConsoleSessions::class);
    }

    public function boot(): void
    {
        // Guard de la consola: solo con la cookie de la consola y nunca dentro de una organización.
        Auth::viaRequest('platform-session', function (Request $request) {
            if (TenancyScope::current() !== null) {
                return null;
            }
            $session = $this->app->make(ConsoleSessions::class)->resolve($request);
            if ($session === null || $session->user === null || ! $session->user->canSignIn()) {
                return null;
            }
            $request->attributes->set(PlatformSession::class, $session);

            return $session->user;
        });

        RateLimiter::for('platform-login', function (Request $request) {
            $identifier = mb_strtolower((string) ($request->input('email') ?? ''));

            return Limit::perMinute((int) config('platform.login_attempts_per_minute'))
                ->by('console|'.sha1($request->ip().'|'.$identifier));
        });

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware(ConsoleBoundary::ALIAS, ConsoleBoundary::class);
        $router->aliasMiddleware(EnsureConsoleSecondFactor::ALIAS, EnsureConsoleSecondFactor::class);
        $router->aliasMiddleware(RequirePlatformPermission::ALIAS, RequirePlatformPermission::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CreatePlatformAdminCommand::class, ResetPlatformMfaCommand::class]);
        }

        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
