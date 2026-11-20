<?php

namespace App\Modules\Documents\Application;

use Illuminate\Support\Facades\DB;

/**
 * DOC-7 · Evidencia inalterable de firma. Cada evento queda en signature_events (solo inserción: la base rechaza
 * UPDATE y DELETE) y se encadena con el anterior de la misma solicitud: event_hash = SHA-256 de su contenido y del
 * event_hash previo. Alterar o borrar un evento rompe la cadena, y alterar el archivo firmado cambia su huella.
 */
final class SignatureEvidence
{
    /**
     * Registra un evento encadenado. Debe llamarse con la solicitud bloqueada (FOR UPDATE) para serializar la cadena.
     *
     * @param  array{signer_id?: string|null, ip?: string|null, user_agent?: string|null, otp_channel?: string|null, otp_reference_hash?: string|null, provider_evidence?: array<string, mixed>|null}  $data
     */
    public function record(string $tenantId, string $requestId, string $event, string $documentHash, array $data = []): string
    {
        // El último eslabón es el evento cuyo hash no es «anterior» de ningún otro (no depende del reloj).
        $previous = DB::table('signature_events as e')->where('e.signature_request_id', $requestId)
            ->whereNotExists(fn ($q) => $q->from('signature_events as n')->whereColumn('n.signature_request_id', 'e.signature_request_id')
                ->whereColumn('n.previous_hash', 'e.event_hash'))
            ->value('e.event_hash');
        $occurredAt = now()->format('Y-m-d\TH:i:s.uP');
        $row = [
            'signature_request_id' => $requestId, 'signer_id' => $data['signer_id'] ?? null, 'event' => $event,
            'document_hash' => $documentHash, 'ip' => $data['ip'] ?? null, 'user_agent' => isset($data['user_agent']) ? mb_substr((string) $data['user_agent'], 0, 500) : null,
            'otp_channel' => $data['otp_channel'] ?? null, 'otp_reference_hash' => $data['otp_reference_hash'] ?? null,
            'provider_evidence' => isset($data['provider_evidence']) ? self::canonical($data['provider_evidence']) : null,
            'occurred_at' => $occurredAt, 'previous_hash' => is_string($previous) ? $previous : null,
        ];
        $row['event_hash'] = self::hash($row);
        $row['timestamp_token'] = $occurredAt;

        return (string) DB::table('signature_events')->insertGetId(['tenant_id' => $tenantId] + $row);
    }

    /**
     * Eventos de la solicitud en orden y si la cadena está íntegra.
     *
     * @return array{chain_valid: bool, events: list<array<string, mixed>>}
     */
    public function chain(string $requestId): array
    {
        $all = DB::table('signature_events')->where('signature_request_id', $requestId)->get();
        // Se recorre la cadena desde el primer eslabón; un evento fuera de la cadena la invalida.
        $byPrevious = $all->groupBy(fn ($r) => (string) ($r->previous_hash ?? ''));
        /** @var list<\stdClass> $rows */
        $rows = [];
        $cursor = '';
        while (isset($byPrevious[$cursor]) && count($rows) < $all->count()) {
            $row = $byPrevious[$cursor]->first();
            if (! $row instanceof \stdClass) {
                break;
            }
            $rows[] = $row;
            $cursor = (string) $row->event_hash;
        }
        $orphans = $all->count() - count($rows);
        $valid = true;
        $previous = null;
        $events = [];
        foreach ($rows as $r) {
            $row = [
                'signature_request_id' => (string) $r->signature_request_id, 'signer_id' => $r->signer_id, 'event' => (string) $r->event,
                'document_hash' => (string) $r->document_hash, 'ip' => $r->ip, 'user_agent' => $r->user_agent,
                'otp_channel' => $r->otp_channel, 'otp_reference_hash' => $r->otp_reference_hash,
                'provider_evidence' => $r->provider_evidence === null ? null : self::canonical((array) json_decode((string) $r->provider_evidence, true)),
                'occurred_at' => (string) $r->timestamp_token, 'previous_hash' => $r->previous_hash,
            ];
            $ok = $r->previous_hash === $previous && hash_equals((string) $r->event_hash, self::hash($row));
            $valid = $valid && $ok;
            $previous = (string) $r->event_hash;
            $events[] = [
                'id' => (string) $r->id, 'event' => (string) $r->event, 'signer_id' => $r->signer_id, 'document_hash' => (string) $r->document_hash,
                'hash_algorithm' => (string) $r->hash_algorithm, 'ip' => $r->ip, 'user_agent' => $r->user_agent, 'otp_channel' => $r->otp_channel,
                'provider_evidence' => $r->provider_evidence === null ? null : json_decode((string) $r->provider_evidence, true),
                'occurred_at' => (string) $r->timestamp_token, 'event_hash' => (string) $r->event_hash, 'previous_hash' => $r->previous_hash, 'valid' => $ok,
            ];
        }

        return ['chain_valid' => $valid && $orphans === 0 && $byPrevious->every(fn ($g) => $g->count() === 1), 'events' => $events];
    }

    /** @param array<mixed> $value */
    private static function canonical(array $value): string
    {
        $sort = function (array $v) use (&$sort): array {
            ksort($v);
            foreach ($v as $k => $item) {
                if (is_array($item)) {
                    $v[$k] = $sort($item);
                }
            }

            return $v;
        };

        return (string) json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $row */
    private static function hash(array $row): string
    {
        $parts = [];
        foreach (['signature_request_id', 'signer_id', 'event', 'document_hash', 'ip', 'user_agent', 'otp_channel', 'otp_reference_hash', 'provider_evidence', 'occurred_at', 'previous_hash'] as $k) {
            $parts[] = $k.'='.($row[$k] ?? '');
        }

        return hash('sha256', implode("\n", $parts));
    }
}
