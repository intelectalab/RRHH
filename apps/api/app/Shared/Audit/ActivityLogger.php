<?php

namespace App\Shared\Audit;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AUD-3 · Actividad del usuario (ingresos, vistas, descargas, búsquedas): un almacén propio, distinto
 * de la auditoría de negocio (AuditLogger) y de los logs técnicos (Log). Aquí no van valores de campos
 * ni textos libres del usuario (lo buscado, por ejemplo): solo qué hizo, sobre qué registro y metadatos
 * no personales.
 */
final class ActivityLogger
{
    /** Claves de metadatos que nunca se guardan (podrían llevar datos personales o lo buscado). */
    private const FORBIDDEN_METADATA = ['q', 'query', 'term', 'search', 'email', 'phone', 'document_number', 'password', 'token', 'code'];

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function record(string $activity, ?string $subjectType = null, ?string $subjectId = null, array $metadata = [], ?string $userId = null): void
    {
        $tenantId = TenancyScope::current();
        if ($tenantId === null) {
            return;
        }
        $metadata = array_diff_key($metadata, array_flip(self::FORBIDDEN_METADATA));

        DB::table('activity_events')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId ?? $this->currentUserId(),
            'activity' => $activity,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }

    private function currentUserId(): ?string
    {
        $id = Auth::guard('ayni')->id();

        return is_string($id) ? $id : null;
    }
}
