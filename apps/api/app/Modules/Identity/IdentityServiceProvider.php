<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Infrastructure\Search\RoleSearchSource;
use App\Modules\Identity\Infrastructure\Search\UserSearchSource;
use App\Modules\Identity\Infrastructure\Subscribers\SendInvitation;
use App\Modules\Identity\Infrastructure\Subscribers\SendPasswordResetLink;
use App\Modules\Identity\Infrastructure\Trash\RoleTrashSource;
use App\Shared\Outbox\OutboxService;
use App\Shared\Search\SearchRegistry;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Trash\TrashRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SessionManager::class);
    }

    public function boot(): void
    {
        // Guard de sesión web: resuelve la cookie dentro de la organización activa (RLS).
        Auth::viaRequest('ayni-session', function (Request $request) {
            if (TenancyScope::current() === null) {
                return null;
            }

            $session = $this->app->make(SessionManager::class)->resolve($request);
            if ($session === null || $session->user === null || ! $session->user->canSignIn()) {
                return null;
            }

            $request->attributes->set('ayni_session', $session);

            return $session->user;
        });

        RateLimiter::for('ayni-login', function (Request $request) {
            $identifier = strtolower((string) ($request->input('email') ?? $request->input('login') ?? $request->input('document_number') ?? ''));

            return Limit::perMinute((int) config('identity.login_attempts_per_minute'))
                ->by(sha1($request->ip().'|'.$request->getHost().'|'.$identifier));
        });

        $outbox = $this->app->make(OutboxService::class);
        $outbox->subscribe($this->app->make(SendPasswordResetLink::class));
        $outbox->subscribe($this->app->make(SendInvitation::class));

        $this->app->make(TrashRegistry::class)->register($this->app->make(RoleTrashSource::class));
        $search = $this->app->make(SearchRegistry::class);
        $search->register($this->app->make(UserSearchSource::class));
        $search->register($this->app->make(RoleSearchSource::class));

        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
