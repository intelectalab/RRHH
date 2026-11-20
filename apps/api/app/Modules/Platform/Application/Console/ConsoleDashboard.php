<?php

namespace App\Modules\Platform\Application\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de la consola (SAS-1); el tablero del negocio está en BusinessMetrics (SAS-10). Solo cifras agregadas: la consola no muestra
 * datos laborales de las organizaciones (M-SAS §3).
 */
final class ConsoleDashboard
{
    /**
     * Auditoría de la consola, de la más reciente a la más antigua.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function auditEvents(int $page, int $perPage, ?string $action, ?string $tenantId = null, ?string $platformUserId = null, ?string $from = null, ?string $to = null): array
    {
        // SAS-13 · Filtros por organización, usuario de la consola y fecha.
        $query = DB::table('platform_audit_events AS e')
            ->leftJoin('platform_users AS u', 'u.id', '=', 'e.platform_user_id')
            ->when($action !== null && $action !== '', fn ($q) => $q->where('e.action', $action))
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->when($platformUserId !== null, fn ($q) => $q->where('e.platform_user_id', $platformUserId))
            ->when($from !== null, fn ($q) => $q->where('e.occurred_at', '>=', Carbon::parse((string) $from)->startOfDay()))
            ->when($to !== null, fn ($q) => $q->where('e.occurred_at', '<=', Carbon::parse((string) $to)->endOfDay()));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('e.occurred_at')->orderByDesc('e.id')
            ->forPage($page, $perPage)
            ->get(['e.id', 'e.action', 'e.entity_type', 'e.entity_id', 'e.tenant_id', 'e.before_data', 'e.after_data', 'e.ip', 'e.occurred_at', 'u.full_name', 'u.email']);

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => (string) $r->id,
                'action' => (string) $r->action,
                'actor' => $r->full_name === null ? null : ['name' => (string) $r->full_name, 'email' => (string) $r->email],
                'entity_type' => $r->entity_type,
                'entity_id' => $r->entity_id,
                'tenant_id' => $r->tenant_id,
                'before' => $r->before_data === null ? null : json_decode((string) $r->before_data, true),
                'after' => $r->after_data === null ? null : json_decode((string) $r->after_data, true),
                'ip' => $r->ip,
                'occurred_at' => Carbon::parse((string) $r->occurred_at)->toIso8601String(),
            ];
        }

        return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }
}
