<?php

namespace App\Modules\Platform\Application\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tablero y auditoría de la consola (SAS-1). Solo cifras agregadas: la consola no muestra
 * datos laborales de las organizaciones (M-SAS §3).
 */
final class ConsoleDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = DB::table('tenants')->whereNull('deleted_at')
            ->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n): int => (int) $n)->all();

        return [
            'tenants' => [
                'total' => array_sum($byStatus),
                'by_status' => (object) $byStatus,
                'new_last_30_days' => DB::table('tenants')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(30))->count(),
                'demo' => DB::table('tenants')->whereNull('deleted_at')->where('is_demo', true)->count(),
            ],
            'users' => [
                'active' => $this->acrossTenants(fn (): int => DB::table('users')->whereNull('deleted_at')->where('status', 'active')->count()),
            ],
            'console_users' => DB::table('platform_users')->whereNull('deleted_at')->where('status', 'active')->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Auditoría de la consola, de la más reciente a la más antigua.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function auditEvents(int $page, int $perPage, ?string $action): array
    {
        $query = DB::table('platform_audit_events AS e')
            ->leftJoin('platform_users AS u', 'u.id', '=', 'e.platform_user_id')
            ->when($action !== null && $action !== '', fn ($q) => $q->where('e.action', $action));
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

    /**
     * Lectura agregada entre organizaciones: con el rol de plataforma (BYPASSRLS) dentro de una
     * transacción, solo aquí, en la consola (regla 1).
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function acrossTenants(callable $read): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $read();
        }

        return DB::transaction(function () use ($read) {
            $row = DB::selectOne("SELECT current_user AS role, pg_has_role(current_user, 'gestia_platform', 'MEMBER') AS ok");
            if ($row === null || ! (bool) $row->ok) {
                return $read();
            }
            DB::statement('SET LOCAL ROLE gestia_platform');
            try {
                return $read();
            } finally {
                // Se vuelve al rol previo aunque la transacción sea un punto de guardado de otra mayor.
                DB::statement('SET LOCAL ROLE '.DB::getPdo()->quote((string) $row->role));
            }
        });
    }
}
