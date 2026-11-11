<?php

namespace App\Modules\Platform\Application\Console;

use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría propia de la consola (SAS-1, regla 5): toda acción queda en platform_audit_events,
 * tabla de solo inserción (los disparadores rechazan UPDATE, DELETE y TRUNCATE). Separada de la
 * auditoría de cada organización: la consola no escribe en audit_events.
 *
 * Nunca guarda claves, secretos ni códigos: solo qué pasó, sobre qué y desde dónde.
 */
final class ConsoleAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?PlatformUser $actor,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $tenantId = null,
    ): void {
        $request = request();

        DB::table('platform_audit_events')->insert([
            'platform_user_id' => $actor?->id,
            'tenant_id' => $tenantId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
