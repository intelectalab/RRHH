<?php

namespace App\Modules\Payroll\Application\Payslips;

use App\Modules\Payroll\Application\PayrollReview;
use App\Modules\Payroll\Application\PayrollRuns;
use App\Modules\Payroll\Domain\PayslipStateMachine;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Files\FileService;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Security\FieldCipher;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * BOL-1 a BOL-5 · Boletas de pago (ME-20).
 *
 * BOL-1: una boleta por relación calculada de la corrida vigente del periodo aprobado o cerrado, con su PDF al
 * legajo (carpeta «Boletas», visible para el titular). Generar es idempotente: la boleta de la misma fila de
 * corrida se conserva; la de otra corrida (recálculo tras reabrir) se anula y la nueva versión la referencia
 * (RN-BOL-04). El contenido, los montos y el archivo no cambian una vez emitida (guarda en base).
 * BOL-2: publicación en el portal, constancia de entrega (publicación, primera vista, descarga, acuse con IP y
 * dispositivo, o entrega en físico con cargo escaneado: RN-BOL-02, 03, 07).
 * BOL-3: historial y descarga masiva por enlace temporal (RN-BOL-08).
 * BOL-4: conservación por el parámetro legal boletas_conservacion_anios (por validar: sin valor no vence nunca).
 * BOL-5: explicación del cambio de neto frente a la boleta anterior desde la traza de cada línea (NOM-6).
 */
final class Payslips
{
    public const SUBJECT_TYPE = 'payslip';

    private const FOLDER = 'payslips';

    private const PERIOD_STATUSES = ['approved', 'closed', 'paid', 'accounted'];

    private const KINDS = ['regular' => 'regular', 'gratification' => 'gratification', 'cts' => 'cts', 'profit_sharing' => 'profit_sharing', 'settlement' => 'settlement', 'special' => 'regular'];

    private const SECTIONS = ['earning' => 'earnings', 'benefit' => 'earnings', 'deduction' => 'deductions', 'tax' => 'deductions', 'employee_contribution' => 'deductions', 'employer_contribution' => 'employer'];

    public function __construct(
        private readonly PayrollRuns $runs,
        private readonly PayrollReview $review,
        private readonly DocumentArchive $archive,
        private readonly FileService $files,
        private readonly ParameterResolver $parameters,
        private readonly LegalParameters $legal,
        private readonly Notifier $notifier,
        private readonly OutboxService $outbox,
        private readonly AuditLogger $audit,
        private readonly PayslipStateMachine $machine,
    ) {}

    /**
     * BOL-1 · Genera las boletas del periodo. Con $autoPublish null decide el parámetro boletas.publicar_al_cerrar.
     *
     * @return array{period_id: string, payroll_run_id: string, generated: int, unchanged: int, voided: int, published: int, payslip_ids: list<string>}
     */
    public function generate(string $periodId, ?string $userId, ?bool $autoPublish = null): array
    {
        $period = $this->period($periodId);
        if (! in_array((string) $period->status, self::PERIOD_STATUSES, true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.payslips.period_not_approved'), [], ['current_status' => (string) $period->status, 'allowed_actions' => []]);
        }
        $runId = $this->review->currentRun($periodId);
        if ($runId === null) {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.payslips.no_run'), [], ['current_status' => (string) $period->status, 'allowed_actions' => []]);
        }
        $rows = DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->where('status', 'calculated')->orderBy('id')
            ->get(['id', 'employment_id', 'gross_amount', 'deductions_amount', 'net_amount', 'employer_cost', 'currency', 'worked_days']);
        $out = ['period_id' => $periodId, 'payroll_run_id' => $runId, 'generated' => 0, 'unchanged' => 0, 'voided' => 0, 'published' => 0, 'payslip_ids' => []];
        $kind = self::KINDS[(string) $period->kind] ?? 'regular';
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $period, $kind, $userId, &$out): void {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payslip:'.$row->employment_id.':'.$period->id]);
                $current = DB::table('payslips')->where('payroll_period_id', $period->id)->where('employment_id', $row->employment_id)
                    ->where('is_imported', false)->orderByDesc('version')->first(['id', 'payroll_run_employment_id', 'version', 'status']);
                $live = $current !== null && (string) $current->status !== PayslipStateMachine::VOIDED;
                if ($live && (string) $current->payroll_run_employment_id === (string) $row->id) {
                    $out['unchanged']++;
                    $out['payslip_ids'][] = (string) $current->id;

                    return;
                }
                $version = 1;
                $rectifies = null;
                if ($current !== null) {
                    // RN-BOL-04 · La corrección es otra versión que referencia a la anterior, que queda anulada.
                    if ($live) {
                        $this->applyVoid((string) $current->id, (string) trans('payroll.payslips.void_recalculated'), $userId);
                        $out['voided']++;
                    }
                    $version = (int) $current->version + 1;
                    $rectifies = (string) $current->id;
                }
                $out['payslip_ids'][] = $this->create($row, $period, $kind, $version, $rectifies, $userId);
                $out['generated']++;
            });
        }
        $publish = $autoPublish ?? (bool) $this->parameters->value('boletas.publicar_al_cerrar');
        if ($publish) {
            $out['published'] = $this->publishPeriod($periodId, $userId)['published'];
        }
        if ($out['generated'] > 0) {
            DB::transaction(fn () => $this->audit->record('payslips.generated', 'payroll_period', $periodId, null, array_diff_key($out, ['payslip_ids' => true])));
        }

        return $out;
    }

    /**
     * BOL-2 · Publica las boletas generadas del periodo.
     *
     * @return array{period_id: string, published: int}
     */
    public function publishPeriod(string $periodId, ?string $userId): array
    {
        $this->period($periodId);
        $ids = DB::table('payslips')->where('payroll_period_id', $periodId)->where('status', PayslipStateMachine::ISSUED)->orderBy('number')->pluck('id');
        foreach ($ids as $id) {
            $this->publish((string) $id, $userId);
        }

        return ['period_id' => $periodId, 'published' => count($ids)];
    }

    /** BOL-2 · Publica una boleta en el portal del titular, deja la constancia y le avisa. */
    public function publish(string $id, ?string $userId): void
    {
        DB::transaction(function () use ($id, $userId): void {
            $p = $this->lock($id);
            $next = $this->machine->getNextState((string) $p->status, 'publish');
            $requires = (bool) $this->parameters->value('boletas.exige_acuse', (string) $p->legal_entity_id);
            DB::table('payslips')->where('id', $id)->update(['status' => $next, 'published_at' => now(), 'requires_acknowledgement' => $requires, 'updated_at' => now()]);
            DB::table('payslip_deliveries')->insertOrIgnore([
                'tenant_id' => TenancyScope::requireTenant(), 'payslip_id' => $id, 'employment_id' => $p->employment_id, 'channel' => 'portal',
                'status' => 'published', 'published_at' => now(), 'delivered_by_user_id' => $userId,
            ]);
            $this->audit->record('payslip.published', 'payslip', $id, ['status' => $p->status], ['status' => $next, 'requires_acknowledgement' => $requires]);
            $this->outbox->record(DomainEventTypes::PAYSLIP_PUBLISHED, 'payslip', $id, ['payslip_id' => $id, 'employee_id' => (string) $p->employment_id, 'period_id' => (string) $p->payroll_period_id]);
            $users = $this->ownerUsers((string) $p->employment_id);
            if ($users !== []) {
                $this->notifier->notify('payslips.published', $users, ['period' => $this->periodLabel($this->period((string) $p->payroll_period_id))], 'payslip', $id);
            }
        });
    }

    /** RN-BOL-04 · Anula una boleta (con motivo). El contenido se conserva; solo cambia el estado. */
    public function void(string $id, string $reason, ?string $userId): void
    {
        if (trim($reason) === '') {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.payslips.reason_required'), [['field' => 'reason', 'rule' => 'required', 'message' => (string) trans('payroll.payslips.reason_required')]]);
        }
        DB::transaction(fn () => $this->applyVoid($id, $reason, $userId));
    }

    /**
     * RN-BOL-02 · El titular abre o descarga su boleta: la primera vez pasa a «vista»; cada descarga queda.
     */
    public function recordView(string $id, bool $download, ?string $ip): void
    {
        DB::transaction(function () use ($id, $download, $ip): void {
            $p = $this->lock($id);
            if ((string) $p->status === PayslipStateMachine::VOIDED || (string) $p->status === PayslipStateMachine::ISSUED) {
                return;
            }
            $d = DB::table('payslip_deliveries')->where('payslip_id', $id)->where('channel', 'portal')->first(['id', 'first_viewed_at', 'status']);
            $changes = ['updated_at' => now()];
            if ($download) {
                $changes['downloaded_at'] = now();
            }
            if ($d !== null && $d->first_viewed_at === null) {
                $changes += ['first_viewed_at' => now(), 'first_viewed_ip' => $ip, 'status' => $d->status === 'acknowledged' ? 'acknowledged' : 'viewed'];
            }
            if ($d !== null) {
                DB::table('payslip_deliveries')->where('id', $d->id)->update($changes);
            }
            if ((string) $p->status === PayslipStateMachine::PUBLISHED) {
                DB::table('payslips')->where('id', $id)->update(['status' => $this->machine->getNextState(PayslipStateMachine::PUBLISHED, 'open'), 'updated_at' => now()]);
                $this->audit->record('payslip.viewed', 'payslip', $id, ['status' => $p->status], ['status' => PayslipStateMachine::VIEWED]);
            }
        });
    }

    /** RN-BOL-03 · Acuse de recibo del titular, con IP y dispositivo como evidencia. */
    public function acknowledge(string $id, ?string $ip, ?string $userAgent): void
    {
        DB::transaction(function () use ($id, $ip, $userAgent): void {
            $p = $this->lock($id);
            $next = $this->machine->getNextState((string) $p->status, 'acknowledge');
            DB::table('payslips')->where('id', $id)->update(['status' => $next, 'updated_at' => now()]);
            DB::table('payslip_deliveries')->where('payslip_id', $id)->where('channel', 'portal')->update([
                'status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_ip' => $ip,
                'acknowledged_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
                'first_viewed_at' => DB::raw('COALESCE(first_viewed_at, now())'), 'updated_at' => now(),
            ]);
            $this->audit->record('payslip.acknowledged', 'payslip', $id, ['status' => $p->status], ['status' => $next]);
        });
    }

    /**
     * RN-BOL-07 · Entrega en físico: el cargo firmado (escaneado en el legajo) vale como acuse.
     *
     * @param  array{delivered_on?: string|null, evidence_document_id?: string|null, note?: string|null}  $input
     */
    public function deliverPrinted(string $id, array $input, ?string $userId): void
    {
        DB::transaction(function () use ($id, $input, $userId): void {
            $p = $this->lock($id);
            $status = (string) $p->status;
            if ($status === PayslipStateMachine::ISSUED) {
                $this->publish($id, $userId);
                $status = PayslipStateMachine::PUBLISHED;
            }
            $next = $this->machine->getNextState($status, 'acknowledge');
            $evidence = $input['evidence_document_id'] ?? null;
            if ($evidence !== null && ! DB::table('documents')->where('id', $evidence)->where('employment_id', $p->employment_id)->whereNull('deleted_at')->exists()) {
                throw new AyniException('validation_failed', 422, (string) trans('payroll.payslips.evidence_invalid'), [['field' => 'evidence_document_id', 'rule' => 'exists', 'message' => (string) trans('payroll.payslips.evidence_invalid')]]);
            }
            $at = isset($input['delivered_on']) ? CarbonImmutable::parse($input['delivered_on'])->startOfDay() : now();
            DB::table('payslips')->where('id', $id)->update(['status' => $next, 'published_at' => DB::raw('COALESCE(published_at, now())'), 'updated_at' => now()]);
            DB::table('payslip_deliveries')->insertOrIgnore([
                'tenant_id' => TenancyScope::requireTenant(), 'payslip_id' => $id, 'employment_id' => $p->employment_id, 'channel' => 'printed',
                'status' => 'acknowledged', 'published_at' => $at, 'acknowledged_at' => $at, 'delivered_by_user_id' => $userId,
                'evidence_document_id' => $evidence, 'note' => $input['note'] ?? null,
            ]);
            $this->audit->record('payslip.delivered_printed', 'payslip', $id, ['status' => $status], ['status' => $next, 'evidence_document_id' => $evidence]);
        });
    }

    /**
     * RN-BOL-03 · Recordatorio diario a quien tiene boletas pendientes de acuse.
     */
    public function remindPending(): int
    {
        $rows = DB::table('payslips AS s')->join('payslip_deliveries AS d', fn ($j) => $j->on('d.payslip_id', '=', 's.id')->where('d.channel', '=', 'portal'))
            ->where('s.requires_acknowledgement', true)->whereIn('s.status', [PayslipStateMachine::PUBLISHED, PayslipStateMachine::VIEWED])
            ->where(fn ($w) => $w->whereNull('d.last_reminded_at')->orWhere('d.last_reminded_at', '<', now()->subHours(23)))
            ->get(['s.id', 's.employment_id', 's.payroll_period_id', 'd.id AS delivery_id']);
        foreach ($rows as $r) {
            $users = $this->ownerUsers((string) $r->employment_id);
            if ($users !== []) {
                $this->notifier->notify('payslips.acknowledgement_pending', $users, ['period' => $this->periodLabel($this->period((string) $r->payroll_period_id))], 'payslip', (string) $r->id);
            }
            DB::table('payslip_deliveries')->where('id', $r->delivery_id)->update(['last_reminded_at' => now()]);
        }

        return count($rows);
    }

    /**
     * Boleta para la API: montos, líneas y constancias; el control de campos sensibles lo hace quien llama.
     *
     * @return array<string, mixed>
     */
    public function present(string $id, bool $withLines = true): array
    {
        $p = DB::table('payslips AS s')->join('employments AS e', 'e.id', '=', 's.employment_id')->join('people AS pe', 'pe.id', '=', 'e.person_id')
            ->join('payroll_periods AS pp', 'pp.id', '=', 's.payroll_period_id')->where('s.id', $id)
            ->first(['s.*', 'e.employee_code', 'e.person_id', 'pe.first_names', 'pe.paternal_surname', 'pe.maternal_surname', 'pp.period_year', 'pp.period_number', 'pp.kind AS period_kind']);
        if ($p === null) {
            throw new AyniException('not_found', 404);
        }
        $money = fn ($v): ?array => $v === null ? null : ['amount' => (string) BigDecimal::of((string) $v)->toScale(2), 'currency' => (string) $p->currency];
        $portal = DB::table('payslip_deliveries')->where('payslip_id', $id)->orderBy('created_at')->get();
        $first = $portal->firstWhere('channel', 'portal');
        $ack = $portal->first(fn ($d) => $d->acknowledged_at !== null);
        /** @var array<string, mixed> $content */
        $content = json_decode((string) $p->content, true) ?: [];
        $data = [
            'id' => (string) $p->id, 'payroll_period_id' => (string) $p->payroll_period_id, 'employment_id' => (string) $p->employment_id,
            'legal_entity_id' => (string) $p->legal_entity_id, 'number' => (string) $p->number, 'kind' => (string) $p->kind, 'version' => (int) $p->version,
            'status' => (string) $p->status, 'person_id' => (string) $p->person_id,
            'employee_name' => trim($p->first_names.' '.$p->paternal_surname.' '.($p->maternal_surname ?? '')), 'employee_code' => (string) $p->employee_code,
            'period_label' => $this->periodLabel((object) ['period_year' => $p->period_year, 'period_number' => $p->period_number, 'kind' => $p->period_kind]),
            'gross' => $money($p->gross_amount), 'deductions' => $money($p->deductions_amount), 'net' => $money($p->net_amount),
            'employer_contributions' => $money($p->employer_contributions),
            'requires_acknowledgement' => (bool) $p->requires_acknowledgement, 'is_imported' => (bool) $p->is_imported,
            'rectifies_payslip_id' => $p->rectifies_payslip_id, 'document_id' => $p->document_id,
            'generated_at' => self::iso($p->created_at), 'published_at' => self::iso($p->published_at),
            'first_viewed_at' => self::iso($first->first_viewed_at ?? null), 'acknowledged_at' => self::iso($ack->acknowledged_at ?? null),
            'voided_at' => self::iso($p->voided_at), 'void_reason' => $p->void_reason, 'retention_until' => $this->retentionUntil($p),
            'allowed_actions' => $this->machine->allowedActions((string) $p->status),
        ];
        if ($withLines) {
            $data['lines'] = array_values((array) ($content['lines'] ?? []));
            $data['worked_days'] = $content['worked_days'] ?? null;
            $data['deliveries'] = $this->deliveries($id);
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function deliveries(string $id): array
    {
        return array_values(DB::table('payslip_deliveries')->where('payslip_id', $id)->orderBy('created_at')->get()->map(fn ($d): array => [
            'id' => (string) $d->id, 'channel' => (string) $d->channel, 'status' => (string) $d->status,
            'published_at' => self::iso($d->published_at), 'first_viewed_at' => self::iso($d->first_viewed_at), 'downloaded_at' => self::iso($d->downloaded_at),
            'acknowledged_at' => self::iso($d->acknowledged_at), 'first_viewed_ip' => $d->first_viewed_ip, 'acknowledged_ip' => $d->acknowledged_ip,
            'acknowledged_user_agent' => $d->acknowledged_user_agent, 'delivered_by_user_id' => $d->delivered_by_user_id,
            'evidence_document_id' => $d->evidence_document_id, 'note' => $d->note,
        ])->all());
    }

    /**
     * BOL-2 · Resumen de entrega del periodo.
     *
     * @param  list<string>|null  $employmentIds  alcance de quien consulta (null: todas)
     * @return array<string, mixed>
     */
    public function summary(string $periodId, ?array $employmentIds): array
    {
        $period = $this->period($periodId);
        $q = DB::table('payslips')->where('payroll_period_id', $periodId);
        if ($employmentIds !== null) {
            $q->whereIn('employment_id', $employmentIds);
        }
        $counts = (clone $q)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status');
        $pending = (clone $q)->where('requires_acknowledgement', true)->whereIn('status', [PayslipStateMachine::PUBLISHED, PayslipStateMachine::VIEWED])->count();
        $printed = DB::table('payslip_deliveries')->where('channel', 'printed')->whereIn('payslip_id', (clone $q)->select('id'))->count();
        $n = fn (string $s): int => (int) ($counts[$s] ?? 0);

        return [
            'period_id' => $periodId, 'period_label' => $this->periodLabel($period), 'period_status' => (string) $period->status,
            'generated' => $n('generated'), 'published' => $n('published'), 'viewed' => $n('viewed'), 'acknowledged' => $n('acknowledged'),
            'voided' => $n('voided'), 'pending_acknowledgement' => $pending, 'printed' => $printed,
            'total' => array_sum(array_map('intval', $counts->all())) - $n('voided'),
        ];
    }

    /**
     * PDF de la boleta (lo sirve el archivo del legajo).
     *
     * @return array{contents: string, name: string, mime: string}
     */
    public function file(string $id, ?string $userId): array
    {
        $p = DB::table('payslips')->where('id', $id)->first(['file_id', 'number']);
        if ($p === null || $p->file_id === null) {
            throw new AyniException('not_found', 404);
        }
        $f = $this->files->contentsForDownload((string) $p->file_id, TenancyScope::requireTenant(), $userId);

        return ['contents' => (string) $f['contents'], 'name' => 'boleta-'.$p->number.'.pdf', 'mime' => 'application/pdf'];
    }

    /** RN-BOL-06 · Lectura de montos de una boleta: queda en el registro de accesos sensibles. */
    public function logAccess(string $id, ?string $userId, string $kind, ?string $ip): void
    {
        $p = DB::table('payslips AS s')->join('employments AS e', 'e.id', '=', 's.employment_id')->where('s.id', $id)->first(['e.person_id']);
        DB::table('data_access_logs')->insert([
            'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $userId, 'access_kind' => $kind, 'data_category' => 'salary',
            'subject_person_id' => $p?->person_id, 'entity_type' => 'Payslip', 'entity_id' => $id,
            'fields' => '{gross,deductions,net,lines}', 'purpose' => 'payslip', 'ip' => $ip, 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * BOL-5 · Qué cambió el neto frente a la boleta anterior de la misma relación y por qué.
     *
     * @return array<string, mixed>
     */
    public function explanation(string $id): array
    {
        $cur = DB::table('payslips AS s')->join('payroll_periods AS pp', 'pp.id', '=', 's.payroll_period_id')->where('s.id', $id)
            ->first(['s.id', 's.employment_id', 's.legal_entity_id', 's.net_amount', 's.currency', 's.content', 'pp.starts_on', 'pp.period_year', 'pp.period_number', 'pp.kind']);
        if ($cur === null) {
            throw new AyniException('not_found', 404);
        }
        $prev = DB::table('payslips AS s')->join('payroll_periods AS pp', 'pp.id', '=', 's.payroll_period_id')
            ->where('s.employment_id', $cur->employment_id)->where('s.status', '<>', PayslipStateMachine::VOIDED)->where('pp.starts_on', '<', $cur->starts_on)->where('pp.kind', $cur->kind)
            ->orderByDesc('pp.starts_on')->orderByDesc('s.version')
            ->first(['s.id', 's.net_amount', 's.content', 'pp.period_year', 'pp.period_number', 'pp.kind']);
        $currency = (string) $cur->currency;
        $m = fn (BigDecimal $v): array => ['amount' => (string) $v->toScale(2), 'currency' => $currency];
        $net = BigDecimal::of((string) $cur->net_amount);
        /** @var array<string, mixed> $content */
        $content = json_decode((string) $cur->content, true) ?: [];
        if ($prev === null) {
            return ['payslip_id' => $id, 'previous_payslip_id' => null, 'net' => $m($net), 'previous_net' => null, 'difference' => null,
                'within_tolerance' => true, 'items' => [], 'summary' => (string) trans('payroll.payslips.explain.first')];
        }
        /** @var array<string, mixed> $before */
        $before = json_decode((string) $prev->content, true) ?: [];
        $prevNet = BigDecimal::of((string) $prev->net_amount);
        $diff = $net->minus($prevNet);
        $tolerance = BigDecimal::of((string) ($this->parameters->value('boletas.tolerancia_explicacion', (string) $cur->legal_entity_id) ?? '0'));
        $index = function (array $c): array {
            $out = [];
            foreach ((array) ($c['lines'] ?? []) as $l) {
                $code = (string) $l['concept_code'];
                $out[$code] = isset($out[$code]) ? array_merge($l, ['amount' => ['amount' => (string) BigDecimal::of($out[$code]['amount']['amount'])->plus($l['amount']['amount']), 'currency' => $l['amount']['currency']]]) : $l;
            }

            return $out;
        };
        $now = $index($content);
        $was = $index($before);
        $items = [];
        foreach (array_unique(array_merge(array_keys($was), array_keys($now))) as $code) {
            $a = isset($was[$code]) ? BigDecimal::of($was[$code]['amount']['amount']) : BigDecimal::zero();
            $b = isset($now[$code]) ? BigDecimal::of($now[$code]['amount']['amount']) : BigDecimal::zero();
            if ($a->isEqualTo($b)) {
                continue;
            }
            $line = $now[$code] ?? $was[$code];
            $section = (string) ($line['section'] ?? 'earnings');
            if ($section === 'employer') {
                continue;
            }
            $change = ! isset($was[$code]) ? 'added' : (! isset($now[$code]) ? 'removed' : 'changed');
            $effect = $section === 'deductions' ? $a->minus($b) : $b->minus($a);
            $vars = ['concept' => (string) $line['concept_name'], 'previous' => self::amount((string) $a), 'current' => self::amount((string) $b), 'currency' => $currency];
            $items[] = [
                'concept_code' => $code, 'concept_name' => (string) $line['concept_name'], 'kind' => (string) ($line['kind'] ?? ''), 'section' => $section,
                'change' => $change, 'previous' => $m($a), 'current' => $m($b), 'effect_on_net' => $m($effect),
                'text' => (string) trans('payroll.payslips.explain.'.$section.'_'.$change, $vars),
                'trace' => isset($now[$code]) ? array_values((array) ($now[$code]['explanation'] ?? [])) : [],
            ];
        }
        usort($items, fn ($x, $y) => BigDecimal::of($y['effect_on_net']['amount'])->abs()->compareTo(BigDecimal::of($x['effect_on_net']['amount'])->abs()));
        $daysNow = $content['worked_days'] ?? null;
        $daysWas = $before['worked_days'] ?? null;
        $within = $diff->abs()->isLessThanOrEqualTo($tolerance);
        $summary = $diff->isZero() ? (string) trans('payroll.payslips.explain.same')
            : (string) trans($diff->isPositive() ? 'payroll.payslips.explain.up' : 'payroll.payslips.explain.down', ['amount' => self::amount((string) $diff->abs()), 'currency' => $currency,
                'previous' => $this->periodLabel($prev)]);
        if ($daysNow !== null && $daysWas !== null && (string) $daysNow !== (string) $daysWas) {
            $summary .= ' '.(string) trans('payroll.payslips.explain.days', ['previous' => (string) $daysWas, 'current' => (string) $daysNow]);
        }
        if (! $within && $items === []) {
            $summary .= ' '.(string) trans('payroll.payslips.explain.unexplained');
        }

        return [
            'payslip_id' => $id, 'previous_payslip_id' => (string) $prev->id, 'previous_period_label' => $this->periodLabel($prev),
            'net' => $m($net), 'previous_net' => $m($prevNet), 'difference' => $m($diff), 'within_tolerance' => $within,
            'worked_days' => $daysNow, 'previous_worked_days' => $daysWas, 'items' => $items, 'summary' => $summary,
        ];
    }

    /**
     * BOL-3 · RN-BOL-08 · ZIP de los PDF elegidos por enlace temporal; queda registrado quién lo pidió.
     *
     * @param  list<string>  $ids
     * @return array{count: int, url: string, expires_in: int}
     */
    public function bulkDownload(array $ids, ?string $userId, ?string $ip): array
    {
        $rows = DB::table('payslips')->whereIn('id', $ids)->whereNotNull('file_id')->orderBy('number')->get(['id', 'number', 'file_id']);
        if ($rows->isEmpty()) {
            throw new AyniException('nothing_to_download', 409, (string) trans('payroll.payslips.nothing_to_download'));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bol');
        $zip = new ZipArchive;
        $zip->open((string) $tmp, ZipArchive::OVERWRITE);
        $tenant = TenancyScope::requireTenant();
        foreach ($rows as $r) {
            $f = $this->files->contentsForDownload((string) $r->file_id, $tenant, $userId);
            $zip->addFromString('boleta-'.$r->number.'.pdf', (string) $f['contents']);
        }
        $zip->close();
        $contents = (string) file_get_contents((string) $tmp);
        @unlink((string) $tmp);
        $ttl = (int) config('files.signed_url_ttl_seconds', 300);
        $file = $this->files->upload($contents, 'boletas-'.now()->format('Ymd-His').'.zip', 'application/zip', $tenant, $userId, 'confidential');
        foreach ($rows as $r) {
            $this->logAccess((string) $r->id, $userId, 'export', $ip);
        }
        DB::transaction(fn () => $this->audit->record('payslips.bulk_downloaded', 'stored_file', (string) $file->id, null, ['count' => count($rows), 'payslip_ids' => $rows->pluck('id')->all()]));

        return ['count' => count($rows), 'url' => $this->files->getSignedUrl((string) $file->id, $tenant, $userId, $ttl), 'expires_in' => $ttl];
    }

    public function employmentOf(string $id): ?string
    {
        $v = DB::table('payslips')->where('id', $id)->value('employment_id');

        return $v === null ? null : (string) $v;
    }

    private function applyVoid(string $id, string $reason, ?string $userId): void
    {
        $p = $this->lock($id);
        $next = $this->machine->getNextState((string) $p->status, 'void');
        DB::table('payslips')->where('id', $id)->update(['status' => $next, 'voided_at' => now(), 'void_reason' => $reason, 'voided_by_user_id' => $userId, 'updated_at' => now()]);
        $this->audit->record('payslip.voided', 'payslip', $id, ['status' => $p->status], ['status' => $next], $reason);
    }

    private function create(\stdClass $row, \stdClass $period, string $kind, int $version, ?string $rectifies, ?string $userId): string
    {
        $tenant = TenancyScope::requireTenant();
        $e = DB::table('employments')->where('id', $row->employment_id)->first(['id', 'person_id', 'legal_entity_id', 'employee_code', 'hire_date']);
        if ($e === null) {
            throw new AyniException('not_found', 404);
        }
        $content = $this->content($row, $period, $e);
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payslip-number:'.$e->legal_entity_id]);
        $seq = (int) DB::table('payslips')->where('legal_entity_id', $e->legal_entity_id)->count() + 1;
        $number = sprintf('%04d-%02d-%06d', (int) $period->period_year, (int) $period->period_number, $seq);
        $contribution = BigDecimal::of((string) ($row->employer_cost ?? '0'))->minus((string) ($row->gross_amount ?? '0'));
        $id = (string) DB::table('payslips')->insertGetId([
            'tenant_id' => $tenant, 'payroll_run_employment_id' => $row->id, 'payroll_period_id' => $period->id, 'employment_id' => $e->id,
            'legal_entity_id' => $e->legal_entity_id, 'number' => $number, 'kind' => $version > 1 ? 'rectification' : $kind, 'status' => PayslipStateMachine::ISSUED,
            'gross_amount' => $row->gross_amount, 'deductions_amount' => $row->deductions_amount, 'net_amount' => $row->net_amount,
            'employer_contributions' => (string) $contribution, 'currency' => $row->currency, 'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'rectifies_payslip_id' => $rectifies, 'version' => $version, 'generated_by_user_id' => $userId,
        ]);
        $pdf = SimplePdf::render((string) trans('payroll.payslips.pdf.title', ['period' => $content['period']['label']]), $this->pdfBody($content, $number, $version),
            (string) trans('payroll.payslips.pdf.footer', ['number' => $number]));
        $documentId = $this->archive->create([
            'category_id' => $this->archive->category(self::FOLDER, (string) trans('documents.folders.payslips', [], 'es'), 'payroll.payslips.view'),
            'title' => (string) trans('payroll.payslips.pdf.title', ['period' => $content['period']['label']]).' · '.$number,
            'employment_id' => $e->id, 'person_id' => $e->person_id, 'legal_entity_id' => $e->legal_entity_id,
            'subject_type' => self::SUBJECT_TYPE, 'subject_id' => $id, 'source' => 'generated', 'status' => 'active',
            'issued_on' => substr((string) ($period->payment_date ?? $period->ends_on), 0, 10), 'classification' => 'confidential', 'visible_to_employee' => true,
        ], $pdf, 'boleta-'.$number.'.pdf', 'application/pdf', $userId);
        $fileId = DB::table('document_versions')->where('document_id', $documentId)->orderByDesc('created_at')->value('stored_file_id');
        DB::table('payslips')->where('id', $id)->update(['document_id' => $documentId, 'file_id' => $fileId, 'content_hash' => hash('sha256', $pdf), 'closed_at' => now(), 'updated_at' => now()]);
        $this->audit->record('payslip.generated', 'payslip', $id, null, ['number' => $number, 'version' => $version, 'rectifies_payslip_id' => $rectifies, 'document_id' => $documentId]);

        return $id;
    }

    /**
     * Contenido de la boleta: empleador, trabajador, periodo, días, ingresos, descuentos, aportes y neto.
     *
     * @return array<string, mixed>
     */
    private function content(\stdClass $row, \stdClass $period, \stdClass $e): array
    {
        $le = DB::table('legal_entities')->where('id', $e->legal_entity_id)->first(['legal_name', 'tax_id', 'address']);
        $person = DB::table('people')->where('id', $e->person_id)->first(['first_names', 'paternal_surname', 'maternal_surname']);
        $doc = DB::table('person_documents')->where('person_id', $e->person_id)->whereNull('deleted_at')->orderByDesc('is_primary')->first(['doc_type', 'number_enc']);
        $pension = DB::table('employment_pension_affiliations')->where('employment_id', $e->id)->where('valid_from', '<=', $period->ends_on)
            ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $period->ends_on))->orderByDesc('valid_from')->first(['pension_system', 'pension_fund_code']);
        $lines = $this->runs->lines((string) $row->id)['lines'];
        $print = DB::table('payroll_concepts')->whereIn('code', array_column($lines, 'concept_code'))->pluck('print_on_payslip', 'code');
        $out = [];
        foreach ($lines as $l) {
            $section = self::SECTIONS[$l['kind']] ?? null;
            if ($section === null || ! (bool) ($print[$l['concept_code']] ?? true) || BigDecimal::of($l['amount']['amount'])->isZero()) {
                continue;
            }
            $out[] = ['concept_code' => $l['concept_code'], 'concept_name' => $l['concept_name'], 'kind' => $l['kind'], 'section' => $section,
                'amount' => $l['amount'], 'explanation' => array_values((array) ($l['explanation']['steps'] ?? []))];
        }
        $money = fn ($v): array => ['amount' => (string) BigDecimal::of((string) ($v ?? '0'))->toScale(2), 'currency' => (string) $row->currency];

        return [
            'employer' => ['legal_name' => $le?->legal_name, 'tax_id' => $le?->tax_id, 'address' => $le?->address],
            'worker' => [
                'name' => $person === null ? '' : trim($person->first_names.' '.$person->paternal_surname.' '.($person->maternal_surname ?? '')),
                'employee_code' => $e->employee_code, 'document_type' => $doc?->doc_type,
                'document_number' => $doc === null ? null : FieldCipher::decrypt((string) $doc->number_enc),
                'hire_date' => substr((string) $e->hire_date, 0, 10), 'pension_system' => $pension?->pension_system, 'pension_fund' => $pension?->pension_fund_code,
            ],
            'period' => ['id' => (string) $period->id, 'label' => $this->periodLabel($period), 'kind' => (string) $period->kind,
                'starts_on' => substr((string) $period->starts_on, 0, 10), 'ends_on' => substr((string) $period->ends_on, 0, 10),
                'payment_date' => $period->payment_date === null ? null : substr((string) $period->payment_date, 0, 10)],
            'worked_days' => $row->worked_days === null ? null : (string) BigDecimal::of((string) $row->worked_days)->stripTrailingZeros(),
            'lines' => $out,
            'totals' => ['gross' => $money($row->gross_amount), 'deductions' => $money($row->deductions_amount), 'net' => $money($row->net_amount),
                'employer_contributions' => $money(BigDecimal::of((string) ($row->employer_cost ?? '0'))->minus((string) ($row->gross_amount ?? '0')))],
        ];
    }

    /** @param  array<string, mixed>  $c */
    private function pdfBody(array $c, string $number, int $version): string
    {
        $t = fn (string $k, array $v = []): string => (string) trans('payroll.payslips.pdf.'.$k, $v);
        $amt = fn (array $m): string => $m['currency'].' '.self::amount((string) $m['amount']);
        $out = [
            $t('employer', ['name' => (string) $c['employer']['legal_name'], 'tax_id' => (string) $c['employer']['tax_id']]),
            $t('worker', ['name' => (string) $c['worker']['name'], 'code' => (string) $c['worker']['employee_code'],
                'document' => trim(strtoupper((string) $c['worker']['document_type']).' '.(string) $c['worker']['document_number'])]),
            $t('period', ['period' => (string) $c['period']['label'], 'from' => (string) $c['period']['starts_on'], 'to' => (string) $c['period']['ends_on'],
                'days' => (string) ($c['worked_days'] ?? '-')]),
            $t('number', ['number' => $number, 'version' => (string) $version]),
        ];
        foreach (['earnings', 'deductions', 'employer'] as $section) {
            $out[] = '';
            $out[] = $t('section_'.$section);
            foreach ($c['lines'] as $l) {
                if ($l['section'] === $section) {
                    $out[] = '  '.$l['concept_name'].': '.$amt($l['amount']);
                }
            }
        }
        $out[] = '';
        $out[] = $t('total_gross', ['amount' => $amt($c['totals']['gross'])]);
        $out[] = $t('total_deductions', ['amount' => $amt($c['totals']['deductions'])]);
        $out[] = $t('total_net', ['amount' => $amt($c['totals']['net'])]);

        return implode("\n", $out);
    }

    private function retentionUntil(\stdClass $p): ?string
    {
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
        $param = $this->legal->at($country, 'boletas_conservacion_anios');
        if ($param === null || $param['pending_validation'] || ! is_numeric($param['value'])) {
            return null;
        }

        return CarbonImmutable::parse((string) ($p->published_at ?? $p->created_at))->addYears((int) $param['value'])->toDateString();
    }

    private function lock(string $id): \stdClass
    {
        $p = DB::table('payslips')->where('id', $id)->lockForUpdate()->first(['id', 'status', 'employment_id', 'payroll_period_id', 'legal_entity_id']);
        if ($p === null) {
            throw new AyniException('not_found', 404);
        }

        return $p;
    }

    private function period(string $id): \stdClass
    {
        $p = DB::table('payroll_periods')->where('id', $id)->first(['id', 'status', 'kind', 'period_year', 'period_number', 'starts_on', 'ends_on', 'payment_date']);
        if ($p === null) {
            throw new AyniException('not_found', 404);
        }

        return $p;
    }

    private function periodLabel(\stdClass $p): string
    {
        return (string) trans('payroll.payslips.period_label', ['year' => (string) $p->period_year, 'number' => sprintf('%02d', (int) $p->period_number),
            'kind' => (string) trans('payroll.payslips.period_kinds.'.$p->kind)]);
    }

    /** @return list<string> */
    private function ownerUsers(string $employmentId): array
    {
        return array_values(DB::table('users AS u')->join('employments AS e', 'e.person_id', '=', 'u.person_id')->where('e.id', $employmentId)
            ->whereNull('u.deleted_at')->pluck('u.id')->map(fn ($v): string => (string) $v)->all());
    }

    /** Monto decimal exacto con separador de miles (sin pasar por float). */
    private static function amount(string $value): string
    {
        $neg = str_starts_with($value, '-');
        [$int, $dec] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');

        return ($neg ? '-' : '').strrev(implode(',', str_split(strrev($int), 3))).'.'.str_pad(substr($dec, 0, 2), 2, '0');
    }

    private static function iso(mixed $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse((string) $v)->toIso8601String();
    }
}
