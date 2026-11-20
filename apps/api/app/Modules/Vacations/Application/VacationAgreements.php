<?php

namespace App\Modules\Vacations\Application;

use App\Modules\Vacations\Domain\VacationAgreementStateMachine;
use App\Modules\Vacations\Infrastructure\Models\VacationAgreement;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Time\VacationPayrollIncidents;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * VAC-6 · Acuerdos escritos de vacaciones (DL 713 · DL 1405):
 *
 * - fraccionamiento: habilita solicitudes por debajo del mínimo de la política, con el bloque mínimo legal;
 * - adelanto: días a cuenta del periodo en curso, dentro del tope de la política y del legal;
 * - venta: reducción del descanso; firmada, descuenta el saldo ganado («sold») y da el concepto de planilla.
 *
 * Cada límite sale de la política y del parámetro legal del régimen (regla 4). Un límite legal sin validar
 * bloquea el adelanto y la venta (D-15: no se usa en producción hasta que el responsable lo valide).
 * El acuerdo se emite en PDF al legajo y queda vigente al registrar el ejemplar firmado (D-10: evidencia propia;
 * cuando exista la firma electrónica de DOC-6, entrará por el mismo registro).
 */
final class VacationAgreements implements VacationPayrollIncidents
{
    public const CATEGORY = 'vacations';

    /** Concepto del paquete de país que paga la venta de días. */
    public const SALE_CONCEPT = 'vacaciones_compra';

    public function __construct(
        private readonly VacationAgreementStateMachine $machine,
        private readonly VacationLedger $ledger,
        private readonly DocumentArchive $archive,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{employment_id: string, kind: string, days?: string|null, period_id?: string|null, effective_on: string, notes?: string|null}  $input
     */
    public function create(array $input, ?string $userId): VacationAgreement
    {
        $kind = $input['kind'];
        $days = isset($input['days']) ? BigDecimal::of((string) $input['days']) : null;
        if ($kind !== 'fractionation' && ($days === null || ! $days->isPositive())) {
            throw ValidationException::withMessages(['days' => [(string) trans('vacations.days_positive')]]);
        }
        $this->checkLimits($input['employment_id'], $kind, $days, $input['effective_on'], $input['period_id'] ?? null, null);

        return DB::transaction(function () use ($input, $kind, $days, $userId): VacationAgreement {
            $a = VacationAgreement::query()->create([
                'employment_id' => $input['employment_id'], 'kind' => $kind, 'days' => $days === null ? null : (string) $days->toScale(2),
                'vacation_accrual_period_id' => $input['period_id'] ?? null, 'effective_on' => $input['effective_on'],
                'notes' => $input['notes'] ?? null, 'created_by_user_id' => $userId,
                'payroll_concept_code' => $kind === 'sale' ? self::SALE_CONCEPT : null,
            ]);
            $this->audit->record('vacation_agreement.created', 'vacation_agreement', $a->id, null, $this->auditable($a));

            return $a;
        });
    }

    /** Emite (o vuelve a emitir) el acuerdo en PDF al legajo del trabajador. */
    public function issue(VacationAgreement $a, ?string $userId): VacationAgreement
    {
        $this->machine->getNextState($a->status, 'issue');
        $this->checkLimits($a->employment_id, $a->kind, $a->days === null ? null : BigDecimal::of($a->days), $a->day('effective_on'), $a->vacation_accrual_period_id, $a->id);
        $e = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->join('legal_entities as le', 'le.id', '=', 'e.legal_entity_id')
            ->where('e.id', $a->employment_id)->first(['e.person_id', 'e.legal_entity_id', 'le.legal_name',
                DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as person_name")]);
        $title = (string) trans('vacations.agreements.title_'.$a->kind);
        $body = (string) trans('vacations.agreements.body_'.$a->kind, [
            'employer' => (string) ($e->legal_name ?? ''), 'employee' => trim((string) ($e->person_name ?? '')),
            'days' => $a->days === null ? '—' : (string) BigDecimal::of($a->days)->stripTrailingZeros(),
            'date' => CarbonImmutable::parse($a->day('effective_on'))->format('d/m/Y'),
        ]).($a->notes !== null && trim($a->notes) !== '' ? "\n\n".$a->notes : '');
        $pdf = SimplePdf::render($title, $body, (string) ($e->legal_name ?? ''));

        return DB::transaction(function () use ($a, $e, $title, $pdf, $userId): VacationAgreement {
            $name = 'acuerdo-vacaciones-'.$a->kind.'.pdf';
            if ($a->document_id !== null) {
                $this->archive->addVersion($a->document_id, $pdf, $name, 'application/pdf', $userId, null, 'draft', 'generated');
                $documentId = $a->document_id;
            } else {
                $documentId = $this->archive->create([
                    'category_id' => $this->archive->category(self::CATEGORY, (string) trans('vacations.agreements.category_name'), 'time.vacation_balances.view'),
                    'title' => $title, 'employment_id' => $a->employment_id, 'person_id' => $e?->person_id, 'legal_entity_id' => $e?->legal_entity_id,
                    'subject_type' => 'vacation_agreement', 'subject_id' => $a->id, 'source' => 'generated', 'status' => 'draft', 'issued_on' => now()->toDateString(),
                ], $pdf, $name, 'application/pdf', $userId);
            }

            return $this->move($a, 'issue', ['document_id' => $documentId]);
        });
    }

    /**
     * Registra el ejemplar firmado: el acuerdo queda vigente. La venta descuenta el saldo en el mismo acto.
     */
    public function registerSignature(VacationAgreement $a, string $contents, string $name, string $mime, string $signedOn, ?string $userId): VacationAgreement
    {
        $this->machine->getNextState($a->status, 'register-signature');

        return DB::transaction(function () use ($a, $contents, $name, $mime, $signedOn, $userId): VacationAgreement {
            $this->archive->addVersion((string) $a->document_id, $contents, $name, $mime, $userId, (string) trans('vacations.agreements.signed_version'), 'active', 'upload');
            $extra = ['signed_at' => CarbonImmutable::parse($signedOn)->startOfDay(), 'signature_origin' => 'physical_scan'];
            if ($a->kind === 'sale') {
                $ids = $this->ledger->sell($a->employment_id, $a->id, (string) $a->days, $a->day('effective_on'), $a->vacation_accrual_period_id,
                    (string) trans('vacations.agreements.sale_reason'), $userId);
                $extra['ledger_entry_id'] = $ids[0] ?? null;
            }

            return $this->move($a, 'register-signature', $extra);
        });
    }

    public function cancel(VacationAgreement $a, string $reason): VacationAgreement
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('vacations.agreements.reason_required')]]);
        }

        return $this->move($a, 'cancel', ['cancel_reason' => $reason], $reason);
    }

    /** Hay un acuerdo de fraccionamiento vigente para la relación a esa fecha. */
    public function fractionationActive(string $employmentId, string $date): bool
    {
        return VacationAgreement::query()->where('employment_id', $employmentId)->where('kind', 'fractionation')
            ->where('status', 'active')->where('effective_on', '<=', $date)->exists();
    }

    public function sales(string $employmentId, string $from, string $to): array
    {
        $out = [];
        foreach (VacationAgreement::query()->where('employment_id', $employmentId)->where('kind', 'sale')->where('status', 'active')
            ->whereBetween('effective_on', [$from, $to])->orderBy('effective_on')->get() as $a) {
            $out[] = ['agreement_id' => $a->id, 'days' => (string) BigDecimal::of((string) $a->days)->toScale(2),
                'effective_on' => $a->day('effective_on'), 'concept_code' => (string) $a->payroll_concept_code];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(VacationAgreement $a): array
    {
        return [
            'id' => $a->id, 'employment_id' => $a->employment_id, 'kind' => $a->kind, 'status' => $a->status,
            'days' => $a->days === null ? null : (string) BigDecimal::of($a->days)->toScale(2),
            'period_id' => $a->vacation_accrual_period_id, 'effective_on' => $a->day('effective_on'),
            'document_id' => $a->document_id, 'signed_at' => $a->signed_at?->toDateString(),
            'payroll_concept_code' => $a->payroll_concept_code, 'ledger_entry_id' => $a->ledger_entry_id,
            'notes' => $a->notes, 'cancel_reason' => $a->cancel_reason,
            'allowed_actions' => $this->machine->allowedActions($a->status),
        ];
    }

    /**
     * Límites de la política y legales del régimen (RN-VAC-04, 05): la venta y el adelanto exigen que la política
     * los permita, que el tope legal esté validado y que el total acordado en el año no lo supere.
     */
    private function checkLimits(string $employmentId, string $kind, ?BigDecimal $days, string $effectiveOn, ?string $periodId, ?string $ignoreId): void
    {
        $policy = $this->ledger->policyFor($employmentId, $effectiveOn);
        /** @var array<string, mixed> $rules */
        $rules = is_array($policy['rules'] ?? null) ? $policy['rules'] : [];
        /** @var array<string, array<string, mixed>> $legal */
        $legal = is_array($policy['legal'] ?? null) ? $policy['legal'] : [];
        if ($periodId !== null && ! DB::table('vacation_accrual_periods')->where('id', $periodId)->where('employment_id', $employmentId)->exists()) {
            throw ValidationException::withMessages(['period_id' => [(string) trans('vacations.period_not_found')]]);
        }
        if ($kind === 'fractionation' || $days === null) {
            return;
        }
        [$allow, $own, $limit] = $kind === 'sale' ? ['allow_sale', 'max_sale_days', 'max_sale_days'] : ['allow_advance', 'max_advance_days', 'max_advance_days'];
        if (($rules[$allow] ?? false) !== true) {
            throw new AyniException($kind.'_not_allowed', 422, (string) trans('vacations.agreements.'.$kind.'_not_allowed'));
        }
        $l = $legal[$limit] ?? null;
        if (! is_array($l) || $l['value'] === null || ($l['pending_validation'] ?? true) === true) {
            throw new AyniException('legal_parameter_pending', 422, (string) trans('vacations.agreements.legal_pending', ['parameter' => (string) ($l['parameter_code'] ?? $limit)]));
        }
        $cap = BigDecimal::of((string) $l['value']);
        if (isset($rules[$own]) && $rules[$own] !== '') {
            $cap = BigDecimal::min($cap, BigDecimal::of((string) $rules[$own]));
        }
        $year = substr($effectiveOn, 0, 4);
        $agreed = VacationAgreement::query()->where('employment_id', $employmentId)->where('kind', $kind)->whereIn('status', ['issued', 'active'])
            ->whereRaw("to_char(effective_on, 'YYYY') = ?", [$year])->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->sum('days');
        $total = BigDecimal::of((string) ($agreed ?: '0'))->plus($days);
        if ($total->isGreaterThan($cap)) {
            throw new AyniException('above_legal_limit', 422, (string) trans('vacations.agreements.above_limit', ['days' => (string) $cap->stripTrailingZeros()]));
        }
        if ($kind === 'sale') {
            $balance = $this->ledger->balance($employmentId);
            $earned = BigDecimal::zero();
            foreach ($balance['periods'] as $p) {
                if (in_array($p['status'], ['earned', 'overdue'], true) && ($periodId === null || $p['id'] === $periodId)) {
                    $earned = $earned->plus((string) $p['available']);
                }
            }
            if ($days->isGreaterThan($earned)) {
                throw new AyniException('insufficient_balance', 422, (string) trans('vacations.agreements.insufficient_balance', ['days' => (string) $days->toScale(2)]));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function move(VacationAgreement $a, string $action, array $extra, ?string $reason = null): VacationAgreement
    {
        $before = $this->auditable($a);
        $a->fill(['status' => $this->machine->getNextState($a->status, $action)] + $extra);
        $a->save();
        $this->audit->record('vacation_agreement.'.str_replace('-', '_', $action), 'vacation_agreement', $a->id, $before, $this->auditable($a), $reason);

        return $a;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(VacationAgreement $a): array
    {
        return ['employment_id' => $a->employment_id, 'kind' => $a->kind, 'status' => $a->status, 'days' => $a->days,
            'effective_on' => $a->day('effective_on'), 'document_id' => $a->document_id, 'ledger_entry_id' => $a->ledger_entry_id];
    }
}
