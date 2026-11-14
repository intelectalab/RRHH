<?php

namespace App\Modules\Absences\Infrastructure\SelfService;

use App\Shared\SelfService\HomeCard;
use Illuminate\Support\Facades\DB;

/**
 * ESS-1 · Tarjeta «Mis ausencias y permisos» del inicio: solicitudes que esperan decisión o sustento y las
 * próximas ausencias aprobadas. El motivo médico nunca viaja aquí (solo el tipo).
 */
final class AbsencesHomeCard implements HomeCard
{
    /** Próximas ausencias que muestra la tarjeta. */
    private const UPCOMING = 3;

    public function key(): string
    {
        return 'absences';
    }

    public function permission(): string
    {
        return 'time.absences.view';
    }

    public function order(): int
    {
        return 20;
    }

    public function card(string $userId, string $employmentId): array
    {
        $today = now()->toDateString();
        $base = DB::table('absence_requests as r')->where('r.employment_id', $employmentId)->whereNull('r.deleted_at');

        $pending = (clone $base)->whereIn('r.status', ['pending', 'pending_document'])->count();
        $upcoming = (clone $base)->join('absence_types as t', 't.id', '=', 'r.absence_type_id')
            ->whereIn('r.status', ['approved', 'in_progress'])->where('r.ends_on', '>=', $today)
            ->orderBy('r.starts_on')->limit(self::UPCOMING)
            ->get(['r.id', 'r.starts_on', 'r.ends_on', 'r.status', 't.name as type_name']);

        return [
            'href' => '/tiempo/ausencias',
            'pending' => $pending,
            'data' => [
                'pending_requests' => $pending,
                'upcoming' => array_values($upcoming->map(fn (object $r): array => [
                    'id' => (string) $r->id,
                    'type_name' => (string) $r->type_name,
                    'starts_on' => substr((string) $r->starts_on, 0, 10),
                    'ends_on' => substr((string) $r->ends_on, 0, 10),
                    'status' => (string) $r->status,
                ])->all()),
            ],
        ];
    }
}
