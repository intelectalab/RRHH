<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DEC-7 · Bitácora de declaraciones: por empresa y periodo, qué declaraciones corresponden (planilla electrónica si
 * hubo trabajadores con relación vigente en el mes, aportes a AFP si alguno estaba afiliado a una AFP, registro
 * laboral si hubo novedades), cuál falta y en qué estado va cada una: generada, presentada, pagada o rectificada,
 * con su responsable y la constancia. El pago y el responsable son datos de la declaración, no estados (ME-24).
 */
final class DeclarationLog
{
    public function __construct(
        private readonly StatutoryFilings $filings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>|null  $entities  empresas visibles (null = todas)
     * @return array{data: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function build(?array $entities, ?string $legalEntityId, string $from, string $to): array
    {
        $q = DB::table('legal_entities')->whereNull('deleted_at')->orderBy('legal_name');
        if ($entities !== null) {
            $q->whereIn('id', $entities);
        }
        if ($legalEntityId !== null) {
            $q->where('id', $legalEntityId);
        }
        $companies = $q->get(['id', 'legal_name']);
        $periods = [];
        $p = CarbonImmutable::create((int) substr($from, 0, 4), (int) substr($from, 4, 2), 1);
        while ($p !== null && $p->format('Ym') <= $to && count($periods) < 24) {
            $periods[] = $p;
            $p = $p->addMonth();
        }
        $rows = [];
        $users = [];
        foreach ($companies as $c) {
            foreach (array_reverse($periods) as $p) {
                $code = $p->format('Ym');
                $start = $p->startOfMonth()->toDateString();
                $end = $p->endOfMonth()->toDateString();
                $active = DB::table('employments')->where('legal_entity_id', $c->id)->whereNull('deleted_at')->whereNotIn('status', ['cancelled', 'pending'])
                    ->where('hire_date', '<=', $end)->where(fn ($w) => $w->whereNull('termination_date')->orWhere('termination_date', '>=', $start))->pluck('id');
                $expected = [
                    'plame' => $active->isNotEmpty(),
                    'afpnet' => $active->isNotEmpty() && DB::table('employment_pension_affiliations')->whereIn('employment_id', $active)->where('pension_system', 'afp')
                        ->where('valid_from', '<=', $end)->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $end))->exists(),
                    't_registro' => DB::table('registry_events')->where('legal_entity_id', $c->id)->whereBetween('event_date', [$start, $end])->exists(),
                ];
                foreach (StatutoryFilings::KINDS as $kind) {
                    $f = DB::table('statutory_filings')->where('legal_entity_id', $c->id)->where('kind', $kind)->where('period_code', $code)
                        ->orderByDesc('created_at')->first();
                    if ($f === null && ! $expected[$kind]) {
                        continue;
                    }
                    $row = $f === null ? null : $this->filings->show((string) $f->id);
                    $responsible = $row['responsible_user_id'] ?? null;
                    if ($responsible !== null) {
                        $users[$responsible] = true;
                    }
                    $rows[] = [
                        'legal_entity_id' => (string) $c->id, 'legal_entity_name' => (string) $c->legal_name, 'period_code' => $code, 'kind' => $kind,
                        'expected' => $expected[$kind], 'missing' => $f === null,
                        'log_status' => $row === null ? 'missing' : $this->logStatus($row),
                        'filing_id' => $row['id'] ?? null, 'filing_status' => $row['status'] ?? null, 'is_rectification' => $row['is_rectification'] ?? false,
                        'due_on' => $row['due_on'] ?? null, 'overdue' => $row['overdue'] ?? false,
                        'responsible_user_id' => $responsible, 'responsible_name' => null,
                        'external_reference' => $row['external_reference'] ?? null, 'submitted_at' => $row['submitted_at'] ?? null,
                        'submission_document_id' => $row['submission_document_id'] ?? null,
                        'paid_at' => $row['paid_at'] ?? null, 'payment_reference' => $row['payment_reference'] ?? null,
                        'payment_document_id' => $row['payment_document_id'] ?? null,
                    ];
                }
            }
        }
        $names = $users === [] ? [] : DB::table('users')->whereIn('id', array_keys($users))->pluck('display_name', 'id')->all();
        $summary = ['missing' => 0, 'generated' => 0, 'submitted' => 0, 'paid' => 0, 'rectified' => 0];
        foreach ($rows as $i => $r) {
            $rows[$i]['responsible_name'] = $r['responsible_user_id'] === null ? null : (string) ($names[$r['responsible_user_id']] ?? '');
            $summary[$r['log_status']]++;
        }

        return ['data' => $rows, 'summary' => $summary];
    }

    /** @param array<string, mixed> $f */
    private function logStatus(array $f): string
    {
        if ($f['paid_at'] !== null) {
            return 'paid';
        }
        if (in_array($f['status'], ['submitted', 'accepted', 'observed', 'rejected'], true)) {
            return 'submitted';
        }
        if ($f['status'] === 'rectified' || $f['is_rectification'] === true) {
            return 'rectified';
        }

        return 'generated';
    }

    /**
     * Registra el pago de una declaración presentada (y su constancia). Solo una vez.
     *
     * @return array<string, mixed>
     */
    public function registerPayment(string $id, string $reference, ?string $paidAt, ?string $documentId, ?string $userId): array
    {
        $f = DB::table('statutory_filings')->where('id', $id)->first();
        if ($f === null) {
            throw new AyniException('not_found', 404);
        }
        if (! in_array((string) $f->status, ['submitted', 'accepted', 'observed'], true) || $f->paid_at !== null) {
            throw new InvalidTransitionException((string) $f->status, [], (string) trans('payroll.declaration_log.not_submitted'));
        }
        $at = $paidAt === null ? now() : CarbonImmutable::parse($paidAt);

        return DB::transaction(function () use ($f, $id, $reference, $at, $documentId, $userId): array {
            DB::table('statutory_filings')->where('id', $id)->update([
                'paid_at' => $at, 'paid_by_user_id' => $userId, 'payment_reference' => $reference, 'payment_document_id' => $documentId,
            ]);
            $this->audit->record('statutory_filing.paid', 'statutory_filing', $id, ['paid_at' => null],
                ['paid_at' => $at->toIso8601ZuluString(), 'payment_reference' => $reference, 'payment_document_id' => $documentId, 'status' => (string) $f->status]);

            return $this->filings->show($id);
        });
    }

    /**
     * Cambia el responsable o adjunta la constancia de presentación. Nunca cambia el estado (regla 3).
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function update(string $id, array $in): array
    {
        $f = DB::table('statutory_filings')->where('id', $id)->first();
        if ($f === null) {
            throw new AyniException('not_found', 404);
        }
        $changes = array_intersect_key($in, array_flip(['responsible_user_id', 'submission_document_id']));
        if ($changes === []) {
            return $this->filings->show($id);
        }
        $before = array_map(fn (string $k): ?string => $f->{$k} === null ? null : (string) $f->{$k}, array_combine(array_keys($changes), array_keys($changes)));

        return DB::transaction(function () use ($id, $changes, $before): array {
            DB::table('statutory_filings')->where('id', $id)->update($changes);
            $this->audit->record('statutory_filing.updated', 'statutory_filing', $id, $before, $changes);

            return $this->filings->show($id);
        });
    }
}
