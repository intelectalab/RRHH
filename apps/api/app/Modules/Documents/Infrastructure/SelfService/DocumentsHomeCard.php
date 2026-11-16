<?php

namespace App\Modules\Documents\Infrastructure\SelfService;

use App\Shared\SelfService\HomeCard;
use Illuminate\Support\Facades\DB;

/**
 * ESS-3 · Tarjeta del inicio del colaborador: sus documentos publicados (solo los visibles para él, RN-DOC
 * y «Solo ve documentos publicados para él») y los más recientes.
 */
final class DocumentsHomeCard implements HomeCard
{
    public function key(): string
    {
        return 'documents';
    }

    public function permission(): string
    {
        return 'documents.documents.view';
    }

    public function order(): int
    {
        return 30;
    }

    public function card(string $userId, string $employmentId): ?array
    {
        $person = DB::table('employments')->where('id', $employmentId)->value('person_id');
        if (! is_string($person)) {
            return null;
        }
        $base = DB::table('documents')->where('owner_person_id', $person)->where('visible_to_employee', true)
            ->whereIn('status', ['active', 'expired', 'pending_approval'])->whereNull('deleted_at');
        $recent = (clone $base)->orderByDesc('created_at')->limit(3)->get(['id', 'title', 'issued_on', 'created_at']);

        return [
            'href' => '/mi/documentos',
            'data' => [
                'count' => (clone $base)->count(),
                'recent' => $recent->map(fn ($d) => [
                    'id' => (string) $d->id, 'title' => (string) $d->title,
                    'issued_on' => $d->issued_on === null ? null : substr((string) $d->issued_on, 0, 10),
                ])->values()->all(),
            ],
        ];
    }
}
