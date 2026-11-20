<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Application\Console\ConsoleSessions;
use App\Modules\Platform\Console\CreatePlatformAdminCommand;
use App\Modules\Platform\Console\ResetPlatformMfaCommand;
use App\Modules\Platform\Console\RunBackupsCommand;
use App\Modules\Platform\Console\RunBillingDunningCommand;
use App\Modules\Platform\Console\RunTrialLifecycleCommand;
use App\Modules\Platform\Console\SnapshotUsageCommand;
use App\Modules\Platform\Console\SyncBundledHelpCommand;
use App\Modules\Platform\Http\Middleware\ConsoleBoundary;
use App\Modules\Platform\Http\Middleware\EnsureConsoleSecondFactor;
use App\Modules\Platform\Http\Middleware\RequirePlatformPermission;
use App\Modules\Platform\Infrastructure\Models\PlatformSession;
use App\Modules\Platform\Infrastructure\Subscribers\DeliverSaasInvoice;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
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
            $this->commands([CreatePlatformAdminCommand::class, ResetPlatformMfaCommand::class, RunTrialLifecycleCommand::class, SnapshotUsageCommand::class, SyncBundledHelpCommand::class, RunBackupsCommand::class, RunBillingDunningCommand::class]);
            // SAS-4 · RN-SAS-04: vencimiento de pruebas y fin de la gracia, una vez al día.
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('platform:subscriptions:trials')->dailyAt('01:15')->withoutOverlapping();
                // SAS-6 · Foto diaria del consumo (historia de la consola y avisos de límite).
                $schedule->command('usage:snapshot')->dailyAt('00:40')->withoutOverlapping();
                // SAS-12 · Copia diaria, vencimiento por retención y prueba de restauración en aislado cuando toca.
                $schedule->command('backups:run')->dailyAt('02:20')->withoutOverlapping();
                // SAS-11 · Avisos de vencimiento, mora y suspensión por falta de pago.
                $schedule->command('billing:dunning')->dailyAt('06:10')->withoutOverlapping();
            });
        }

        // SAS-11 · El comprobante del SaaS sale al proveedor de facturación electrónica por el outbox.
        $this->app->make(OutboxService::class)->subscribe($this->app->make(DeliverSaasInvoice::class));

        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
    }
}
