<?php

namespace App\Modules\Organization\Console;

use App\Modules\Organization\Infrastructure\Models\Position;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * POS-2 · RN-POS-04 · Ocupada o vacante se deriva de las asignaciones vigentes: cada día se recalcula
 * para las asignaciones que empiezan o terminan de regir (ingresos y ceses con fecha). Idempotente.
 */
final class SyncPositionOccupancy extends Command
{
    protected $signature = 'positions:sync-occupancy';

    protected $description = 'Recalcula posiciones ocupadas y vacantes según las asignaciones vigentes hoy';

    public function handle(TenantDirectory $tenants, PositionOccupancy $occupancy): int
    {
        $changed = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $changed += TenancyScope::runAs($tenantId, function () use ($occupancy): int {
                $n = 0;
                Position::query()->whereIn('status', ['approved', 'open', 'recruiting', 'filled'])
                    ->orderBy('id')->chunk(500, function ($chunk) use ($occupancy, &$n): void {
                        foreach ($chunk as $position) {
                            /** @var Position $position */
                            $before = $position->status;
                            if ($occupancy->sync($position->id) !== $before) {
                                $n++;
                            }
                        }
                    });

                return $n;
            });
        }
        $this->info("Posiciones actualizadas: {$changed}");

        return self::SUCCESS;
    }
}
