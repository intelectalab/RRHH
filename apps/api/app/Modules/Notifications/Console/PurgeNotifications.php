<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Application\NotificationCatalog;
use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * NOT-2 · Archiva los avisos leídos más antiguos que la retención de la organización
 * (parámetro notificaciones.retencion_dias). No se borran: dejan de mostrarse.
 */
final class PurgeNotifications extends Command
{
    protected $signature = 'notifications:archive-old';

    protected $description = 'Archiva los avisos leídos que superan la retención';

    public function handle(TenantDirectory $tenants): int
    {
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += (int) TenancyScope::runAs($tenantId, function (): int {
                $days = app(NotificationCatalog::class)->setting('notificaciones.retencion_dias');
                if (! is_numeric($days) || (int) $days < 1) {
                    return 0;
                }

                return Notification::query()->whereNotNull('read_at')->whereNull('archived_at')
                    ->where('created_at', '<', now()->subDays((int) $days))->update(['archived_at' => now()]);
            });
        }
        $this->info("Avisos archivados: {$total}");

        return self::SUCCESS;
    }
}
