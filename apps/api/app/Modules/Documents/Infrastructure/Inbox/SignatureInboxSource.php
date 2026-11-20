<?php

namespace App\Modules\Documents\Infrastructure\Inbox;

use App\Modules\Documents\Domain\SignatureRequestStateMachine as M;
use App\Shared\Inbox\InboxSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** DOC-6 · Documentos por firmar en la bandeja de pendientes: solo cuando es el turno del firmante. */
final class SignatureInboxSource implements InboxSource
{
    public function kind(): string
    {
        return 'signature';
    }

    public function permission(): string
    {
        return 'documents.signature_requests.sign';
    }

    public function items(string $userId, int $limit): array
    {
        return $this->query($userId)->orderBy('r.expires_at')->limit($limit)->get(['r.id', 'r.title', 'r.expires_at', 'r.sent_at'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'type' => 'signature_request',
                'title' => (string) trans('documents.signatures.inbox_title', ['document' => (string) $r->title]),
                'subtitle' => null,
                'due_at' => $r->expires_at === null ? null : (string) $r->expires_at,
                'overdue' => false,
                'created_at' => $r->sent_at === null ? null : (string) $r->sent_at,
                'href' => '/mi/firmas?id='.$r->id,
            ])->values()->all();
    }

    public function count(string $userId): int
    {
        return $this->query($userId)->count();
    }

    /** Solicitudes abiertas donde el usuario tiene el turno: es el primero sin firmar (secuencial) o falta su firma (paralelo). */
    private function query(string $userId): Builder
    {
        return DB::table('signature_requests as r')->join('signature_request_signers as s', 's.signature_request_id', '=', 'r.id')
            ->whereIn('r.status', M::OPEN)->where('s.user_id', $userId)->whereIn('s.status', ['sent', 'viewed']);
    }
}
