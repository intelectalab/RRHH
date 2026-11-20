<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\CtsDepositStateMachine;
use App\Modules\Payroll\Domain\ProfitSharingStateMachine;
use App\Modules\Payroll\Infrastructure\Models\CtsDeposit;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Modules\Payroll\Infrastructure\Models\ProfitSharingDistribution;
use App\Modules\Payroll\Infrastructure\PayrollParameters;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Labor\MasterData;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\AbsenceIncidents;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BSO-2 · Depósitos de CTS del proceso de CTS (uno por trabajador con su entidad depositaria; al depositar se emite
 * la liquidación de depósito al legajo y al portal, RN-BSO-06) y BSO-3 · distribución anual de utilidades
 * (RN-BSO-08 a RN-BSO-10): porcentaje de la actividad sobre la renta, mitad por días laborados y mitad por
 * remuneraciones, tope individual, cesados del ejercicio incluidos. Los porcentajes, el mínimo de trabajadores y el
 * tope son parámetros con vigencia; los montos se guardan con decimal exacto.
 */
final class SocialBenefits
{
    /** Corridas cuyos resultados cuentan como remuneraciones del ejercicio (periodos ya aprobados o cerrados). */
    private const FINAL_RUNS = ['approved', 'closed'];

    /** Tramo máximo del resumen de ausencias (AUS-6). */
    private const SUMMARY_MONTHS = 3;

    public const ACTIVITIES = ['industria', 'comercio_restaurantes', 'otras_actividades'];

    public function __construct(
        private readonly CtsDepositStateMachine $ctsMachine,
        private readonly ProfitSharingStateMachine $profitMachine,
        private readonly DocumentArchive $archive,
        private readonly AuditLogger $audit,
        private readonly LegalParameters $legal,
        private readonly AbsenceIncidents $absences,
        private readonly MasterData $masterData,
    ) {}

    // ── CTS ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Depósitos del proceso de CTS a partir de su último cálculo: crea los que faltan y actualiza los que aún no se
     * depositaron. Quien quedó con error (sin entidad depositaria, RN-BSO-05) no tiene depósito.
     *
     * @param  list<string>|null  $visible  relaciones del alcance del usuario (null = todas)
     * @return list<array<string, mixed>>
     */
    public function ctsDeposits(PayrollPeriod $p, ?array $visible, bool $withAmounts): array
    {
        if ($p->kind !== 'cts') {
            throw new AyniException('not_a_cts_period', 409, (string) trans('payroll.benefits.not_cts_period'));
        }
        $run = DB::table('payroll_runs')->where('payroll_period_id', $p->id)->whereIn('kind', ['calculation', 'recalculation'])
            ->whereNotIn('status', ['discarded', 'queued', 'running', 'failed'])->orderByDesc('run_number')->value('id');
        $code = 'CTS-'.substr((string) $p->getRawOriginal('ends_on'), 0, 7);
        if ($run !== null) {
            $rows = DB::table('payroll_run_employments as re')
                ->join('payroll_run_lines as l', 'l.payroll_run_employment_id', '=', 're.id')
                ->join('payroll_concepts as c', 'c.id', '=', 'l.payroll_concept_id')
                ->where('re.payroll_run_id', $run)->where('re.status', 'calculated')->where('c.code', 'cts')
                ->get(['re.id', 're.employment_id', 're.context_snapshot', 'l.amount', 'l.currency']);
            foreach ($rows as $r) {
                $ctx = json_decode((string) $r->context_snapshot, true);
                $vars = (array) ($ctx['variables'] ?? []);
                $benefit = (array) ($ctx['sources']['benefit'] ?? []);
                $account = (array) ($benefit['deposit_account'] ?? []);
                $existing = DB::table('cts_deposits')->where('employment_id', $r->employment_id)->where('period_code', $code)->first(['id', 'status']);
                $values = [
                    'payroll_period_id' => $p->id, 'payroll_run_employment_id' => $r->id, 'amount' => (string) $r->amount, 'currency' => (string) $r->currency,
                    'computable_months' => (int) ($vars['meses_computables'] ?? 0), 'computable_days' => (int) ($vars['dias_computables'] ?? 0),
                    'computable_remuneration' => (string) ($vars['sueldo'] ?? '0'),
                    'sixth_of_bonus' => (string) ($vars['gratificacion_ultima'] ?? '0'),
                    'deposit_bank_code' => $account['bank_code'] ?? null, 'person_bank_account_id' => $account['id'] ?? null,
                    'due_on' => $p->getRawOriginal('payment_date') ?? $p->getRawOriginal('ends_on'),
                ];
                if ($existing === null) {
                    DB::table('cts_deposits')->insert($values + ['tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $r->employment_id,
                        'period_code' => $code, 'status' => 'calculated']);
                } elseif ($existing->status === 'calculated') {
                    DB::table('cts_deposits')->where('id', $existing->id)->update($values + ['updated_at' => now()]);
                }
            }
        }
        $query = DB::table('cts_deposits as d')->join('employments as e', 'e.id', '=', 'd.employment_id')
            ->join('people as pe', 'pe.id', '=', 'e.person_id')->where('d.period_code', $code)->orderBy('pe.paternal_surname');
        if ($visible !== null) {
            $query->whereIn('d.employment_id', $visible);
        }

        return array_values($query->get(['d.*', 'e.employee_code', DB::raw("concat_ws(' ', pe.first_names, pe.paternal_surname, pe.maternal_surname) as person_name")])
            ->map(fn ($d): array => $this->presentDeposit($d, $withAmounts))->all());
    }

    /**
     * Registra el depósito (fecha y referencia del banco) y emite la liquidación de depósito de CTS al legajo,
     * visible para el trabajador en el portal (RN-BSO-06). Repetirlo con la misma referencia no duplica.
     *
     * @return array<string, mixed>
     */
    public function deposit(CtsDeposit $d, string $depositedOn, string $reference, ?string $userId): array
    {
        if (in_array($d->status, ['notified', 'deposited'], true) && $d->deposit_reference === $reference) {
            return $this->presentDeposit(DB::table('cts_deposits')->where('id', $d->id)->first(), true);
        }
        $before = $d->status;
        $next = $this->ctsMachine->getNextState($d->status, 'deposit');
        $employment = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $d->employment_id)
            ->first(['e.person_id', 'e.legal_entity_id', 'e.employee_code', DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as name")]);
        $entity = DB::table('legal_entities')->where('id', $employment?->legal_entity_id)->first(['legal_name', 'tax_id']);
        $body = implode("\n", [
            (string) trans('payroll.benefits.statement.employer', ['name' => $entity->legal_name ?? '', 'tax_id' => $entity->tax_id ?? '']),
            (string) trans('payroll.benefits.statement.worker', ['name' => $employment->name ?? '', 'code' => $employment->employee_code ?? '']),
            (string) trans('payroll.benefits.statement.period', ['period' => $d->period_code]),
            (string) trans('payroll.benefits.statement.time', ['months' => (int) $d->computable_months, 'days' => (int) $d->computable_days]),
            (string) trans('payroll.benefits.statement.base', ['salary' => (string) $d->computable_remuneration, 'gratification' => (string) $d->sixth_of_bonus, 'currency' => $d->currency]),
            (string) trans('payroll.benefits.statement.amount', ['amount' => (string) $d->amount, 'currency' => $d->currency]),
            (string) trans('payroll.benefits.statement.bank', ['bank' => (string) $d->deposit_bank_code, 'date' => $depositedOn, 'reference' => $reference]),
        ]);
        $pdf = SimplePdf::render((string) trans('payroll.benefits.statement.title'), $body, (string) trans('payroll.benefits.statement.footer'));

        return DB::transaction(function () use ($d, $before, $next, $employment, $pdf, $depositedOn, $reference, $userId): array {
            $documentId = $this->archive->create([
                'category_id' => $this->archive->category('cts_statement', (string) trans('payroll.benefits.statement.category', [], 'es'), 'payroll.benefit_runs.view'),
                'title' => (string) trans('payroll.benefits.statement.title').' · '.$d->period_code, 'employment_id' => $d->employment_id,
                'person_id' => $employment?->person_id, 'legal_entity_id' => $employment?->legal_entity_id,
                'subject_type' => 'cts_deposit', 'subject_id' => $d->id, 'source' => 'generated', 'status' => 'active', 'issued_on' => $depositedOn,
                'classification' => 'confidential', 'visible_to_employee' => true,
            ], $pdf, 'cts-'.$d->period_code.'-'.($employment->employee_code ?? $d->id).'.pdf', 'application/pdf', $userId);
            DB::table('cts_deposits')->where('id', $d->id)->update(['status' => $next, 'deposited_on' => $depositedOn, 'deposit_reference' => $reference,
                'deposited_by_user_id' => $userId, 'statement_document_id' => $documentId, 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
            $this->audit->record('cts_deposit.deposited', 'cts_deposit', $d->id, ['status' => $before], ['status' => $next, 'deposited_on' => $depositedOn,
                'deposit_reference' => $reference, 'statement_document_id' => $documentId]);

            return $this->presentDeposit(DB::table('cts_deposits')->where('id', $d->id)->first(), true);
        });
    }

    /** @return array<string, mixed> */
    private function presentDeposit(mixed $d, bool $withAmounts): array
    {
        abort_unless($d instanceof \stdClass, 404);
        $out = [
            'id' => (string) $d->id, 'employment_id' => (string) $d->employment_id, 'period_code' => (string) $d->period_code,
            'computable_months' => (int) $d->computable_months, 'computable_days' => (int) $d->computable_days,
            'deposit_bank_code' => $d->deposit_bank_code, 'due_on' => $d->due_on === null ? null : substr((string) $d->due_on, 0, 10),
            'deposited_on' => $d->deposited_on === null ? null : substr((string) $d->deposited_on, 0, 10), 'deposit_reference' => $d->deposit_reference ?? null,
            'statement_document_id' => $d->statement_document_id ?? null, 'status' => (string) $d->status,
            'allowed_actions' => $this->ctsMachine->allowedActions((string) $d->status),
        ];
        foreach (['person_name', 'employee_code'] as $k) {
            if (isset($d->{$k})) {
                $out[$k] = (string) $d->{$k};
            }
        }
        if ($withAmounts) {
            $out['amount'] = ['amount' => (string) $d->amount, 'currency' => (string) $d->currency];
            $out['computable_remuneration'] = ['amount' => (string) ($d->computable_remuneration ?? '0'), 'currency' => (string) $d->currency];
            $out['last_gratification'] = ['amount' => (string) ($d->sixth_of_bonus ?? '0'), 'currency' => (string) $d->currency];
        }

        return $out;
    }

    // ── Utilidades ────────────────────────────────────────────────────────────────────────────────────

    /** @param  array{legal_entity_id: string, fiscal_year: int, net_income_before_tax: array{amount: string, currency?: string}, activity: string, due_on?: string|null}  $data */
    public function createDistribution(array $data, ?string $userId): ProfitSharingDistribution
    {
        $currency = (string) DB::table('legal_entities')->where('id', $data['legal_entity_id'])->value('currency');
        if (DB::table('profit_sharing_distributions')->where('legal_entity_id', $data['legal_entity_id'])->where('fiscal_year', $data['fiscal_year'])->exists()) {
            throw new AyniException('conflict', 409, (string) trans('payroll.benefits.distribution_exists', ['year' => $data['fiscal_year']]));
        }
        $d = ProfitSharingDistribution::create([
            'legal_entity_id' => $data['legal_entity_id'], 'fiscal_year' => $data['fiscal_year'], 'activity' => $data['activity'],
            'net_income_before_tax' => $data['net_income_before_tax']['amount'], 'currency' => $currency !== '' ? $currency : ($data['net_income_before_tax']['currency'] ?? 'PEN'),
            'due_on' => $data['due_on'] ?? null, 'status' => 'draft', 'created_by_user_id' => $userId,
        ]);
        $this->audit->record('profit_sharing.created', 'profit_sharing_distribution', $d->id, null, ['fiscal_year' => $data['fiscal_year'], 'activity' => $data['activity']]);

        return $d;
    }

    /**
     * Reparte el porcentaje de la actividad sobre la renta: una parte por días laborados y la otra por remuneraciones
     * del ejercicio (conceptos que suman a la base «utilidades» en planillas aprobadas o cerradas). Incluye a quienes
     * cesaron en el ejercicio (RN-BSO-10). Sin el mínimo de trabajadores de la norma no hay reparto (RN-BSO-08).
     */
    public function calculate(ProfitSharingDistribution $d, ?string $userId): ProfitSharingDistribution
    {
        $next = $this->profitMachine->getNextState($d->status, 'calculate');
        $year = (int) $d->fiscal_year;
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $country = (string) DB::table('legal_entities')->where('id', $d->legal_entity_id)->value('country_code');
        $params = new PayrollParameters($this->legal, $country, null, (string) $d->legal_entity_id, $to);
        $rate = $this->required($params, 'utilidades_pct_'.$d->activity);
        $byDaysPct = $this->required($params, 'utilidades_pct_por_dias');
        $minimum = $this->required($params, 'utilidades_minimo_trabajadores');
        $cap = $params->resolve('utilidades_tope_remuneraciones');
        $capValue = $cap !== null && $cap['value'] !== null && is_numeric($cap['value']) ? BigDecimal::of((string) $cap['value']) : null;

        $workers = [];
        $employments = DB::table('employments')->where('legal_entity_id', $d->legal_entity_id)->whereNull('deleted_at')
            ->where('hire_date', '<=', $to)->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from))
            ->get(['id', 'hire_date', 'termination_date']);
        foreach ($employments as $e) {
            $start = max($from, substr((string) $e->hire_date, 0, 10));
            $end = $e->termination_date === null ? $to : min($to, substr((string) $e->termination_date, 0, 10));
            $days = $this->workedDays((string) $e->id, $start, $end);
            $rem = $this->remunerations((string) $e->id, $from, $to);
            if ($days->isZero() && $rem->isZero()) {
                continue;
            }
            $workers[(string) $e->id] = ['days' => $days, 'rem' => $rem, 'terminated' => $e->termination_date !== null];
        }
        if (BigDecimal::of(count($workers))->isLessThanOrEqualTo($minimum)) {
            throw new AyniException('profit_sharing_not_required', 422, (string) trans('payroll.benefits.below_threshold', ['count' => count($workers), 'minimum' => (string) $minimum]));
        }

        $total = BigDecimal::of((string) $d->net_income_before_tax)->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);
        $poolDays = $total->multipliedBy($byDaysPct)->dividedBy(100, 2, RoundingMode::HALF_UP);
        $poolRem = $total->minus($poolDays);
        $sumDays = array_reduce($workers, fn (BigDecimal $c, array $w) => $c->plus($w['days']), BigDecimal::zero());
        $sumRem = array_reduce($workers, fn (BigDecimal $c, array $w) => $c->plus($w['rem']), BigDecimal::zero());
        $excess = BigDecimal::zero();
        $warnings = $capValue === null ? [['code' => 'cap_pending_validation', 'message' => (string) trans('payroll.benefits.cap_pending')]] : [];

        DB::transaction(function () use ($d, $workers, $poolDays, $poolRem, $sumDays, $sumRem, $capValue, $to, &$excess): void {
            $tenant = TenancyScope::requireTenant();
            $seen = [];
            foreach ($workers as $employmentId => $w) {
                $aDays = $sumDays->isZero() ? BigDecimal::zero() : $poolDays->multipliedBy($w['days'])->dividedBy($sumDays, 2, RoundingMode::HALF_UP);
                $aRem = $sumRem->isZero() ? BigDecimal::zero() : $poolRem->multipliedBy($w['rem'])->dividedBy($sumRem, 2, RoundingMode::HALF_UP);
                $amount = $aDays->plus($aRem);
                $capped = false;
                if ($capValue !== null) {
                    $limit = $capValue->multipliedBy($this->monthlySalary($employmentId, $to))->toScale(2, RoundingMode::HALF_UP);
                    if ($amount->isGreaterThan($limit)) {
                        $excess = $excess->plus($amount->minus($limit));
                        $amount = $limit;
                        $capped = true;
                    }
                }
                $values = ['days_worked' => (string) $w['days'], 'remunerations' => (string) $w['rem'], 'amount_by_days' => (string) $aDays,
                    'amount_by_remuneration' => (string) $aRem, 'amount' => (string) $amount, 'cap_applied' => $capped, 'currency' => $d->currency];
                $id = DB::table('profit_sharing_items')->where('profit_sharing_distribution_id', $d->id)->where('employment_id', $employmentId)->value('id');
                if ($id === null) {
                    DB::table('profit_sharing_items')->insert($values + ['tenant_id' => $tenant, 'profit_sharing_distribution_id' => $d->id, 'employment_id' => $employmentId]);
                } else {
                    DB::table('profit_sharing_items')->where('id', $id)->update($values);
                }
                $seen[] = $employmentId;
            }
            // Quien ya no califica en un recálculo queda en cero (la liquidación anterior no se borra).
            DB::table('profit_sharing_items')->where('profit_sharing_distribution_id', $d->id)->whereNotIn('employment_id', $seen)
                ->update(['amount' => '0', 'amount_by_days' => '0', 'amount_by_remuneration' => '0', 'days_worked' => '0', 'remunerations' => '0']);
        });

        $before = $d->status;
        $d->forceFill([
            'status' => $next, 'participation_rate' => (string) $rate, 'total_amount' => (string) $total, 'total_days_worked' => (string) $sumDays,
            'total_remunerations' => (string) $sumRem, 'workers_count' => count($workers), 'excess_amount' => (string) $excess,
            'warnings' => json_encode($warnings), 'calculated_at' => now(),
        ])->save();
        $this->audit->record('profit_sharing.calculated', 'profit_sharing_distribution', $d->id, ['status' => $before],
            ['status' => $next, 'workers' => count($workers), 'participation_rate' => (string) $rate]);

        return $d->refresh();
    }

    public function approve(ProfitSharingDistribution $d, ?string $userId): ProfitSharingDistribution
    {
        $before = $d->status;
        $next = $this->profitMachine->getNextState($d->status, 'approve');
        $d->forceFill(['status' => $next, 'approved_by_user_id' => $userId, 'approved_at' => now()])->save();
        $this->audit->record('profit_sharing.approved', 'profit_sharing_distribution', $d->id, ['status' => $before], ['status' => $next]);

        return $d->refresh();
    }

    /**
     * @param  list<string>|null  $visible
     * @return array<string, mixed>
     */
    public function presentDistribution(ProfitSharingDistribution $d, bool $withAmounts, ?array $visible = null, bool $withItems = false): array
    {
        $currency = (string) $d->currency;
        $out = [
            'id' => $d->id, 'legal_entity_id' => (string) $d->legal_entity_id, 'fiscal_year' => (int) $d->fiscal_year, 'activity' => (string) $d->activity,
            'participation_rate' => $d->participation_rate === null ? null : (string) $d->participation_rate, 'workers_count' => $d->workers_count === null ? null : (int) $d->workers_count,
            'total_days_worked' => $d->total_days_worked === null ? null : (string) $d->total_days_worked,
            'due_on' => $d->getRawOriginal('due_on') === null ? null : substr((string) $d->getRawOriginal('due_on'), 0, 10),
            'status' => (string) $d->status, 'allowed_actions' => $this->profitMachine->allowedActions((string) $d->status),
            'warnings' => json_decode((string) ($d->warnings ?? '[]'), true) ?: [],
            'calculated_at' => $d->calculated_at === null ? null : CarbonImmutable::parse((string) $d->getRawOriginal('calculated_at'))->toIso8601String(),
        ];
        if ($withAmounts) {
            foreach (['net_income_before_tax', 'total_amount', 'total_remunerations', 'excess_amount'] as $k) {
                $out[$k] = $d->{$k} === null ? null : ['amount' => (string) $d->{$k}, 'currency' => $currency];
            }
        }
        if ($withItems) {
            $q = DB::table('profit_sharing_items as i')->join('employments as e', 'e.id', '=', 'i.employment_id')->join('people as p', 'p.id', '=', 'e.person_id')
                ->where('i.profit_sharing_distribution_id', $d->id)->orderBy('p.paternal_surname');
            if ($visible !== null) {
                $q->whereIn('i.employment_id', $visible);
            }
            $out['items'] = array_values($q->get(['i.*', 'e.employee_code', 'e.termination_date', DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as person_name")])
                ->map(function ($i) use ($withAmounts, $currency): array {
                    $row = ['employment_id' => (string) $i->employment_id, 'person_name' => (string) $i->person_name, 'employee_code' => (string) ($i->employee_code ?? ''),
                        'terminated' => $i->termination_date !== null, 'days_worked' => (string) $i->days_worked, 'cap_applied' => (bool) $i->cap_applied];
                    if ($withAmounts) {
                        $row['remunerations'] = ['amount' => (string) $i->remunerations, 'currency' => $currency];
                        foreach (['amount_by_days', 'amount_by_remuneration', 'amount'] as $k) {
                            $row[$k] = ['amount' => (string) $i->{$k}, 'currency' => $currency];
                        }
                    }

                    return $row;
                })->all());
        }

        return $out;
    }

    private function required(PayrollParameters $params, string $code): BigDecimal
    {
        $p = $params->resolve($code);
        if ($p === null || $p['value'] === null || ! is_numeric($p['value'])) {
            throw new AyniException('missing_parameter', 422, (string) trans('payroll.context.missing_parameter', ['parameter' => $code]));
        }

        return BigDecimal::of((string) $p['value']);
    }

    /** Días laborados del ejercicio: días trabajados (incluidos los remotos) del resumen de ausencias (AUS-6), por tramos. */
    private function workedDays(string $employmentId, string $from, string $to): BigDecimal
    {
        if ($from > $to) {
            return BigDecimal::zero();
        }
        $total = 0;
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addMonthsNoOverflow(self::SUMMARY_MONTHS)->startOfMonth()) {
            $end = min($to, $d->addMonthsNoOverflow(self::SUMMARY_MONTHS - 1)->endOfMonth()->toDateString());
            $summary = $this->absences->summary($employmentId, $d->toDateString(), $end);
            $totals = (array) ($summary['totals'] ?? []);
            $total += (int) ($totals['worked_days'] ?? 0); // incluye los días en remoto (AUS-5)
        }

        return BigDecimal::of($total);
    }

    /** Remuneraciones del ejercicio: líneas de conceptos que suman a la base «utilidades» en planillas aprobadas o cerradas. */
    private function remunerations(string $employmentId, string $from, string $to): BigDecimal
    {
        $sum = DB::table('payroll_run_lines as l')
            ->join('payroll_run_employments as re', 're.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_runs as r', 'r.id', '=', 're.payroll_run_id')
            ->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')
            ->join('payroll_concept_affectations as a', function ($j): void {
                $j->on('a.payroll_concept_version_id', '=', 'l.payroll_concept_version_id')->where('a.base_code', '=', 'utilidades')->where('a.affects', '=', true);
            })
            ->where('re.employment_id', $employmentId)->whereIn('r.status', self::FINAL_RUNS)->whereBetween('p.ends_on', [$from, $to])
            ->sum('l.amount');

        return BigDecimal::of((string) $sum);
    }

    private function monthlySalary(string $employmentId, string $date): BigDecimal
    {
        $salary = $this->masterData->salaryAt($employmentId, $date);

        return BigDecimal::of($salary['amount'] ?? '0');
    }
}
