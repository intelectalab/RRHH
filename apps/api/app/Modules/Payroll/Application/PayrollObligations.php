<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Application\Declarations\StatutoryFilings;
use App\Modules\Payroll\Infrastructure\Models\PayrollObligationAssignment;
use App\Shared\Audit\AuditLogger;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Settings\ParameterResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CUM-9 · Tablero mínimo de obligaciones de planilla de una empresa: PLAME, AFPnet, T-Registro, depósito de CTS,
 * pago de gratificación, contratos por vencer y umbral del comité de SST. Se calcula al vuelo con los datos de
 * nómina (declaraciones, eventos del T-Registro, depósitos, periodos y contratos) y los plazos como parámetros
 * legales con vigencia: si un plazo no está cargado, la obligación queda «sin datos» con el parámetro que falta,
 * nunca con un número inventado. Funciona sin los módulos de SST y de cumplimiento.
 */
final class PayrollObligations
{
    public const CODES = ['plame', 'afpnet', 't_registro', 'cts_deposit', 'gratification_payment', 'contracts_expiring', 'sst_committee'];

    private const SUBMITTED = ['submitted', 'accepted', 'observed', 'rectified'];

    public function __construct(
        private readonly LegalParameters $legal,
        private readonly ParameterResolver $parameters,
        private readonly StatutoryFilings $filings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function board(string $legalEntityId, ?string $asOf = null): array
    {
        $today = CarbonImmutable::parse($asOf ?? now()->toDateString())->startOfDay();
        $notice = max(0, (int) ($this->parameters->value('cumplimiento.aviso_dias', $legalEntityId, $today->toDateString()) ?? 0));
        $items = [
            $this->monthlyFiling($legalEntityId, $today, 'plame', 'plame_vencimiento_dia_habil'),
            $this->monthlyFiling($legalEntityId, $today, 'afpnet', 'afpnet_plazo_dias_habiles'),
            $this->registry($legalEntityId, $today),
            $this->cts($legalEntityId, $today),
            $this->gratification($legalEntityId, $today),
            $this->contracts($legalEntityId, $today),
            $this->sst($legalEntityId, $today),
        ];
        $responsible = DB::table('payroll_obligation_assignments AS a')->join('users AS u', 'u.id', '=', 'a.responsible_user_id')
            ->where('a.legal_entity_id', $legalEntityId)->get(['a.obligation_code', 'a.responsible_user_id', 'u.display_name'])->keyBy('obligation_code');

        return array_map(function (array $i) use ($today, $notice, $responsible): array {
            if ($i['status'] === null) {
                $i['status'] = $i['due_on'] === null ? 'no_data' : $this->timing($today, (string) $i['due_on'], $notice);
            }
            $r = $responsible->get($i['code']);
            $i['name'] = (string) trans('payroll.obligations.names.'.$i['code']);
            $i['responsible'] = $r === null ? null : ['user_id' => (string) $r->responsible_user_id, 'name' => (string) $r->display_name];

            return $i;
        }, $items);
    }

    /** @return array<string, mixed> */
    public function assign(string $legalEntityId, string $code, string $userId): array
    {
        $row = PayrollObligationAssignment::query()->firstOrNew(['legal_entity_id' => $legalEntityId, 'obligation_code' => $code]);
        $before = $row->exists ? ['responsible_user_id' => $row->responsible_user_id] : null;
        $row->responsible_user_id = $userId;
        $row->save();
        $this->audit->record('payroll_obligation.responsible_assigned', 'legal_entity', $legalEntityId, $before, ['obligation_code' => $code, 'responsible_user_id' => $userId]);

        return ['legal_entity_id' => $legalEntityId, 'obligation_code' => $code, 'responsible_user_id' => $userId];
    }

    private function timing(CarbonImmutable $today, string $dueOn, int $notice): string
    {
        $due = CarbonImmutable::parse($dueOn);
        if ($due->lessThan($today)) {
            return 'overdue';
        }

        return $today->diffInDays($due) <= $notice ? 'due_soon' : 'pending';
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     * @return array<string, mixed>
     */
    private function item(string $code, string $periodKey, ?string $dueOn, ?string $status, ?array $evidence, string $explanation, ?string $missing = null): array
    {
        return ['code' => $code, 'period_key' => $periodKey, 'due_on' => $dueOn, 'status' => $status, 'evidence' => $evidence,
            'explanation' => $explanation, 'missing_parameter' => $missing];
    }

    /**
     * PLAME y AFPnet del mes anterior: vencen el n-ésimo día hábil del mes en curso.
     *
     * @return array<string, mixed>
     */
    private function monthlyFiling(string $entity, CarbonImmutable $today, string $kind, string $param): array
    {
        $period = $today->startOfMonth()->subMonth();
        $code = $period->format('Ym');
        $filing = DB::table('statutory_filings')->where('legal_entity_id', $entity)->where('kind', $kind)->where('period_code', $code)
            ->orderByDesc('created_at')->first(['id', 'status', 'submitted_at']);
        $label = $period->format('Y-m');
        if ($filing !== null && in_array((string) $filing->status, self::SUBMITTED, true)) {
            return $this->item($kind, $code, null, 'met', ['type' => 'statutory_filing', 'id' => (string) $filing->id, 'status' => (string) $filing->status],
                (string) trans('payroll.obligations.explain.filing_met', ['period' => $label]));
        }
        $n = $this->legal->at('PE', $param, $today->startOfMonth());
        if ($n === null || ! is_numeric($n['value'])) {
            return $this->item($kind, $code, null, 'no_data', null, (string) trans('payroll.obligations.explain.missing_parameter', ['parameter' => $param]), $param);
        }
        $due = $this->filings->nthWorkingDay($entity, $today->startOfMonth(), (int) $n['value']);

        return $this->item($kind, $code, $due, null, $filing === null ? null : ['type' => 'statutory_filing', 'id' => (string) $filing->id, 'status' => (string) $filing->status],
            (string) trans('payroll.obligations.explain.filing_pending', ['period' => $label, 'days' => (int) $n['value']]));
    }

    /**
     * T-Registro: el plazo más próximo de los eventos (altas, bajas, cambios) que esperan presentación.
     *
     * @return array<string, mixed>
     */
    private function registry(string $entity, CarbonImmutable $today): array
    {
        $pending = DB::table('registry_events')->where('legal_entity_id', $entity)->whereIn('status', ['pending', 'overdue']);
        $count = (clone $pending)->count();
        if ($count === 0) {
            return $this->item('t_registro', $today->format('Ymd'), null, 'met', null, (string) trans('payroll.obligations.explain.registry_met'));
        }
        $due = (clone $pending)->min('due_on');

        return $this->item('t_registro', $due === null ? $today->format('Ymd') : str_replace('-', '', substr((string) $due, 0, 10)),
            $due === null ? null : substr((string) $due, 0, 10), null, null, (string) trans('payroll.obligations.explain.registry_pending', ['count' => $count]));
    }

    /** Mes del beneficio que toca vigilar: el del mes en curso si es uno de los meses del parámetro; si no, el próximo. */
    private function benefitMonth(CarbonImmutable $today, mixed $months): ?CarbonImmutable
    {
        if (! is_array($months) || $months === []) {
            return null;
        }
        $list = array_values(array_filter(array_map('intval', $months), fn (int $m): bool => $m >= 1 && $m <= 12));
        sort($list);
        foreach ([0, 1] as $add) {
            foreach ($list as $m) {
                $candidate = CarbonImmutable::create($today->year + $add, $m, 1);
                if ($candidate !== null && $candidate->endOfMonth()->greaterThanOrEqualTo($today)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** @return array{0: CarbonImmutable|null, 1: string|null, 2: string|null} mes, vencimiento y parámetro faltante */
    private function benefitDue(CarbonImmutable $today, string $monthsParam, string $dayParam): array
    {
        $months = $this->legal->at('PE', $monthsParam, $today);
        $month = $this->benefitMonth($today, $months['value'] ?? null);
        if ($month === null) {
            return [null, null, $monthsParam];
        }
        $day = $this->legal->at('PE', $dayParam, $month);
        if ($day === null || ! is_numeric($day['value'])) {
            return [$month, null, $dayParam];
        }

        return [$month, $month->setDay(min((int) $day['value'], $month->daysInMonth))->toDateString(), null];
    }

    /** @return array<string, mixed> */
    private function cts(string $entity, CarbonImmutable $today): array
    {
        [$month, $due, $missing] = $this->benefitDue($today, 'cts_meses_deposito', 'cts_deposito_dia_limite');
        if ($month === null) {
            return $this->item('cts_deposit', '', null, 'no_data', null, (string) trans('payroll.obligations.explain.missing_parameter', ['parameter' => $missing]), $missing);
        }
        $key = $month->format('Ym');
        $codes = ['CTS-'.$month->format('Y-m'), 'CTS-'.$month->subMonth()->format('Y-m')];
        $deposits = DB::table('cts_deposits AS d')->join('employments AS e', 'e.id', '=', 'd.employment_id')->where('e.legal_entity_id', $entity)
            ->where(fn ($w) => $w->whereIn('d.period_code', $codes)->orWhereBetween('d.due_on', [$month->toDateString(), $month->endOfMonth()->toDateString()]));
        $total = (clone $deposits)->count();
        $done = (clone $deposits)->whereIn('d.status', ['deposited', 'notified'])->count();
        if ($total > 0 && $done === $total) {
            return $this->item('cts_deposit', $key, $due, 'met', ['type' => 'cts_deposits', 'count' => $done], (string) trans('payroll.obligations.explain.cts_met', ['count' => $done]));
        }
        if ($due === null) {
            return $this->item('cts_deposit', $key, null, 'no_data', null, (string) trans('payroll.obligations.explain.missing_parameter', ['parameter' => $missing]), $missing);
        }

        return $this->item('cts_deposit', $key, $due, null, $total === 0 ? null : ['type' => 'cts_deposits', 'count' => $done],
            (string) trans('payroll.obligations.explain.cts_pending', ['done' => $done, 'total' => $total]));
    }

    /** @return array<string, mixed> */
    private function gratification(string $entity, CarbonImmutable $today): array
    {
        [$month, $due, $missing] = $this->benefitDue($today, 'gratificacion_meses', 'gratificacion_pago_dia_limite');
        if ($month === null) {
            return $this->item('gratification_payment', '', null, 'no_data', null, (string) trans('payroll.obligations.explain.missing_parameter', ['parameter' => $missing]), $missing);
        }
        $key = $month->format('Ym');
        $period = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('g.legal_entity_id', $entity)
            ->where('p.kind', 'gratification')->where('p.period_year', $month->year)
            ->whereRaw('EXTRACT(MONTH FROM COALESCE(p.payment_date, p.ends_on)) = ?', [$month->month])->orderByDesc('p.created_at')->first(['p.id', 'p.status']);
        if ($period !== null && in_array((string) $period->status, ['paid', 'accounted'], true)) {
            return $this->item('gratification_payment', $key, $due, 'met', ['type' => 'payroll_period', 'id' => (string) $period->id, 'status' => (string) $period->status],
                (string) trans('payroll.obligations.explain.gratification_met'));
        }
        if ($due === null) {
            return $this->item('gratification_payment', $key, null, 'no_data', null, (string) trans('payroll.obligations.explain.missing_parameter', ['parameter' => $missing]), $missing);
        }

        return $this->item('gratification_payment', $key, $due, null, $period === null ? null : ['type' => 'payroll_period', 'id' => (string) $period->id, 'status' => (string) $period->status],
            (string) trans('payroll.obligations.explain.gratification_pending'));
    }

    /**
     * Contratos vigentes que vencen dentro del mayor aviso configurado (contratos.alerta_vencimiento_dias).
     *
     * @return array<string, mixed>
     */
    private function contracts(string $entity, CarbonImmutable $today): array
    {
        $days = $this->parameters->value('contratos.alerta_vencimiento_dias', $entity, $today->toDateString());
        $window = is_array($days) && $days !== [] ? max(array_map('intval', $days)) : (int) ($days ?? 0);
        $q = DB::table('contracts AS c')->join('employments AS e', 'e.id', '=', 'c.employment_id')->where('e.legal_entity_id', $entity)
            ->whereNull('c.deleted_at')->whereIn('c.status', ['active', 'signed'])->whereNotNull('c.valid_to')
            ->whereBetween('c.valid_to', [$today->toDateString(), $today->addDays($window)->toDateString()]);
        $count = (clone $q)->count();
        $expired = DB::table('contracts AS c')->join('employments AS e', 'e.id', '=', 'c.employment_id')->where('e.legal_entity_id', $entity)
            ->whereNull('c.deleted_at')->where('c.status', 'expired')->count();
        $next = (clone $q)->min('c.valid_to');
        if ($expired > 0) {
            return $this->item('contracts_expiring', $today->format('Ymd'), null, 'overdue', ['type' => 'contracts', 'count' => $expired],
                (string) trans('payroll.obligations.explain.contracts_expired', ['count' => $expired]));
        }
        if ($count === 0) {
            return $this->item('contracts_expiring', $today->format('Ymd'), null, 'met', null, (string) trans('payroll.obligations.explain.contracts_none', ['days' => $window]));
        }

        return $this->item('contracts_expiring', str_replace('-', '', substr((string) $next, 0, 10)), substr((string) $next, 0, 10), null, ['type' => 'contracts', 'count' => $count],
            (string) trans('payroll.obligations.explain.contracts_pending', ['count' => $count, 'days' => $window]));
    }

    /**
     * Umbral del comité de SST: solo avisa si la empresa lo alcanza; no gestiona el comité.
     *
     * @return array<string, mixed>
     */
    private function sst(string $entity, CarbonImmutable $today): array
    {
        $min = $this->legal->at('PE', 'sst_comite_minimo_trabajadores', $today);
        $workers = DB::table('employments')->where('legal_entity_id', $entity)->whereNull('deleted_at')->whereIn('status', ['active', 'suspended'])->count();
        if ($min === null || ! is_numeric($min['value'])) {
            return $this->item('sst_committee', $today->format('Y'), null, 'no_data', null,
                (string) trans('payroll.obligations.explain.missing_parameter', ['parameter' => 'sst_comite_minimo_trabajadores']), 'sst_comite_minimo_trabajadores');
        }
        $reached = $workers >= (int) $min['value'];

        return $this->item('sst_committee', $today->format('Y'), null, $reached ? 'attention' : 'not_applicable', ['type' => 'headcount', 'count' => $workers],
            (string) trans($reached ? 'payroll.obligations.explain.sst_reached' : 'payroll.obligations.explain.sst_below', ['count' => $workers, 'min' => (int) $min['value']]));
    }
}
