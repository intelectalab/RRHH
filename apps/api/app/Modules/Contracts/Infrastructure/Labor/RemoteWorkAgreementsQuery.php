<?php

namespace App\Modules\Contracts\Infrastructure\Labor;

use App\Shared\Labor\RemoteWorkAgreements;
use Illuminate\Support\Facades\DB;

/**
 * AUS-5 · Implementación del puerto RemoteWorkAgreements: un acuerdo cuenta desde que se registra firmado
 * (vigente) hasta su fecha de término; uno terminado sigue contando para las fechas que cubrió.
 */
final class RemoteWorkAgreementsQuery implements RemoteWorkAgreements
{
    public function activeOn(string $employmentId, string $date): ?array
    {
        $row = DB::table('remote_work_agreements')->where('employment_id', $employmentId)->whereNull('deleted_at')
            ->whereIn('status', ['active', 'ended'])
            ->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->orderByDesc('starts_on')->first(['id', 'modality', 'starts_on', 'ends_on', 'disconnection_hours']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'modality' => (string) $row->modality,
            'starts_on' => substr((string) $row->starts_on, 0, 10),
            'ends_on' => $row->ends_on === null ? null : substr((string) $row->ends_on, 0, 10),
            'disconnection_hours' => $row->disconnection_hours === null ? null : (int) $row->disconnection_hours,
        ];
    }
}
