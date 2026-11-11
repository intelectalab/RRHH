<?php

namespace App\Modules\Audit\Application;

use App\Shared\Files\ColdArchiveStorage;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AUD-5 · Verifica la cadena de hash de la auditoría de la organización activa y deja el resultado.
 *
 * Detecta:
 *  - altered: el hash guardado no coincide con el contenido de la entrada (se cambió un valor);
 *  - broken_link: el hash anterior no coincide con la entrada previa (se reescribió la cadena);
 *  - missing: faltan números de secuencia que tampoco están en el archivo frío (se borró una entrada);
 *  - duplicate: dos entradas con el mismo número;
 *  - unchained: entradas sin sellar (insertadas saltándose el trigger);
 *  - head_mismatch: la última entrada no coincide con la cabeza de la cadena (se truncó el final);
 *  - archive_missing / archive_altered: el archivo frío ya no está o su huella cambió.
 */
final class AuditChainVerifier
{
    private const MAX_ISSUES = 100;

    /** @var array<int, array<string, mixed>> */
    private array $issues = [];

    private int $issueCount = 0;

    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly AuditStorePresenter $presenter,
        private readonly ColdArchiveStorage $storage,
    ) {}

    /**
     * @return array<string, mixed> la verificación registrada
     */
    public function verify(): array
    {
        TenancyScope::requireTenant();
        $this->issues = [];
        $this->issueCount = 0;
        $started = now();

        [$ranges, $endHashes, $archivedRows] = $this->checkArchives();

        $unchained = DB::table('audit_events')->whereNull('chain_seq')->count();
        if ($unchained > 0) {
            $this->issue(['type' => 'unchained', 'count' => $unchained]);
        }

        $head = DB::table('audit_chain_heads')->first();
        $headSeq = $head === null ? 0 : (int) $head->last_seq;

        $prevSeq = 0;
        $prevHash = null;
        $checked = 0;
        $first = null;
        $rows = DB::table('audit_events')->whereNotNull('chain_seq')->orderBy('chain_seq')
            ->selectRaw('id, chain_seq, prev_hash, row_hash, audit_event_hash(tenant_id, chain_seq, prev_hash, id, occurred_at, actor_user_id, actor_kind, action, entity_type, entity_id, before_data, after_data, changed_fields, reason, ip, user_agent, request_id) AS computed')
            ->cursor();
        foreach ($rows as $row) {
            $seq = (int) $row->chain_seq;
            $checked++;
            $first ??= $seq;
            if ($row->row_hash !== $row->computed) {
                $this->issue(['type' => 'altered', 'seq' => $seq, 'entry_id' => (string) $row->id]);
            }
            if ($seq === $prevSeq) {
                $this->issue(['type' => 'duplicate', 'seq' => $seq, 'entry_id' => (string) $row->id]);
            } elseif ($seq === $prevSeq + 1) {
                if ($row->prev_hash !== $prevHash) {
                    $this->issue(['type' => 'broken_link', 'seq' => $seq, 'entry_id' => (string) $row->id]);
                }
            } elseif (! $this->covered($ranges, $prevSeq + 1, $seq - 1)) {
                $this->issue(['type' => 'missing', 'from_seq' => $prevSeq + 1, 'to_seq' => $seq - 1]);
            } elseif (array_key_exists($seq - 1, $endHashes) && $row->prev_hash !== $endHashes[$seq - 1]) {
                $this->issue(['type' => 'broken_link', 'seq' => $seq, 'entry_id' => (string) $row->id]);
            }
            $prevSeq = $seq;
            $prevHash = $row->row_hash;
        }

        if ($headSeq > $prevSeq && ! $this->covered($ranges, $prevSeq + 1, $headSeq)) {
            $this->issue(['type' => 'missing', 'from_seq' => $prevSeq + 1, 'to_seq' => $headSeq]);
        } elseif ($head !== null && $headSeq === $prevSeq && $prevSeq > 0 && $head->last_hash !== $prevHash) {
            $this->issue(['type' => 'head_mismatch', 'seq' => $headSeq]);
        }

        $id = (string) Str::uuid();
        $status = $this->issueCount === 0 ? 'ok' : 'failed';

        DB::transaction(function () use ($id, $status, $checked, $archivedRows, $unchained, $first, $prevSeq, $started): void {
            DB::table('audit_integrity_checks')->insert([
                'id' => $id,
                'tenant_id' => TenancyScope::requireTenant(),
                'status' => $status,
                'checked_rows' => $checked,
                'archived_rows' => $archivedRows,
                'unchained_rows' => $unchained,
                'first_seq' => $first,
                'last_seq' => $prevSeq === 0 ? null : $prevSeq,
                'issues' => json_encode($this->issues, JSON_THROW_ON_ERROR),
                'started_at' => $started,
                'finished_at' => now(),
            ]);
            if ($status === 'failed') {
                $event = $this->outbox->record(DomainEventTypes::AUDIT_INTEGRITY_FAILED, 'audit_integrity_check', $id, [
                    'check_id' => $id,
                    'issues_count' => $this->issueCount,
                ]);
                $this->outbox->deliverSoon($event);
            }
        });

        if ($status === 'failed') {
            // Log técnico sin datos personales: solo el identificador de la verificación.
            Log::warning('audit.integrity_failed', ['check_id' => $id, 'issues' => $this->issueCount]);
        }

        $row = DB::table('audit_integrity_checks')->where('id', $id)->first();

        return $row === null ? [] : $this->presenter->check($row);
    }

    /**
     * Revisa la huella de los archivos fríos aún vigentes y arma los rangos archivados.
     *
     * @return array{0: array<int, array{0: int, 1: int}>, 1: array<int, string|null>, 2: int}
     */
    private function checkArchives(): array
    {
        $ranges = [];
        $endHashes = [];
        $rows = 0;
        foreach (DB::table('audit_archives')->orderBy('period_month')->get() as $archive) {
            $rows += (int) $archive->row_count;
            if ($archive->from_seq !== null && $archive->to_seq !== null) {
                $ranges[] = [(int) $archive->from_seq, (int) $archive->to_seq];
                $endHashes[(int) $archive->to_seq] = $archive->last_hash;
            }
            if ($archive->purged_at !== null) {
                continue;
            }
            $period = substr((string) $archive->period_month, 0, 7);
            $disk = (string) $archive->storage_disk;
            $key = (string) $archive->storage_key;
            if (! $this->storage->exists($disk, $key)) {
                $this->issue(['type' => 'archive_missing', 'period' => $period]);
            } elseif (hash('sha256', (string) $this->storage->get($disk, $key)) !== $archive->sha256) {
                $this->issue(['type' => 'archive_altered', 'period' => $period]);
            }
        }
        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return [$ranges, $endHashes, $rows];
    }

    /**
     * ¿Todos los números entre $from y $to están dentro de algún rango archivado?
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges  ordenados por inicio
     */
    private function covered(array $ranges, int $from, int $to): bool
    {
        if ($from > $to) {
            return true;
        }
        $next = $from;
        foreach ($ranges as [$start, $end]) {
            if ($start > $next) {
                break;
            }
            $next = max($next, $end + 1);
            if ($next > $to) {
                return true;
            }
        }

        return $next > $to;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issue(array $issue): void
    {
        $this->issueCount++;
        if (count($this->issues) < self::MAX_ISSUES) {
            $this->issues[] = $issue;
        }
    }
}
