<?php

namespace App\Modules\Payroll\Infrastructure;

use App\Modules\Payroll\Domain\Engine\CalculationContext;
use App\Modules\Payroll\Domain\Engine\ContextVariables;
use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Shared\Exceptions\AyniException;
use App\Shared\Labor\MasterData;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Time\AbsenceIncidents;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NOM-5 · Contexto de cálculo de una relación laboral en un periodo, leído solo de las fuentes oficiales y
 * por sus puertos: sueldo y asignación de MasterData (HIS-6), régimen (NOM-2), días y ausencias del resumen
 * de ausencias (AUS-6, que sin módulo de asistencia sale del calendario, las ausencias aprobadas y las
 * incidencias importadas) e incidencias del periodo (payroll_incidents).
 *
 * Un cambio de sueldo o de régimen dentro del periodo parte el periodo en tramos (RN-NOM-05): el sueldo
 * proporcional suma cada tramo por sus días sobre la base del régimen (parámetro del paquete de país).
 */
final class ContextBuilder
{
    /** Escala interna de los prorrateos; el redondeo final lo pone cada concepto. */
    private const SCALE = 10;

    public function __construct(
        private readonly MasterData $master,
        private readonly AbsenceIncidents $absences,
        private readonly RegimeResolver $regimes,
        private readonly LegalParameters $legal,
    ) {}

    /**
     * @param  array<string, string|bool>  $overrides  datos hipotéticos del banco de pruebas (solo nombres conocidos)
     * @param  array<string, array{amount?: string, quantity?: string}>  $extraIncidents  incidencias hipotéticas por código
     * @return array{context: CalculationContext, country: string, legal_entity_id: string, decimals: int, pack_version: string}
     */
    public function build(string $employmentId, string $from, string $to, string $periodKind = 'regular', ?string $periodId = null, array $overrides = [], array $extraIncidents = []): array
    {
        $emp = DB::table('employments')->where('id', $employmentId)->whereNull('deleted_at')->first(['id', 'legal_entity_id', 'hire_date', 'termination_date']);
        if ($emp === null) {
            throw new AyniException('not_found', 404);
        }
        $entity = DB::table('legal_entities')->where('id', $emp->legal_entity_id)->first(['country_code', 'currency']);
        $country = (string) ($entity->country_code ?? '');
        $currency = (string) ($entity->currency ?? '');
        $hire = substr((string) $emp->hire_date, 0, 10);
        $termination = $emp->termination_date === null ? null : substr((string) $emp->termination_date, 0, 10);
        $start = max($from, $hire);
        $end = $termination === null ? $to : min($to, $termination);
        $errors = [];
        $segments = [];
        $pack = new DataPayrollCountryPack($country);
        $base = null;
        $regimeAtEnd = null;
        $sources = ['pack_version' => $pack->version(), 'salary_rows' => [], 'regime_rows' => []];

        if (! DataPayrollCountryPack::exists($country)) {
            $errors[] = self::error('country_not_supported', ['country' => $country]);
        } elseif ($start > $end) {
            $errors[] = self::error('outside_employment');
        } else {
            for ($d = $start; $d <= $end;) {
                $salary = $this->master->salaryAt($employmentId, $d);
                $regime = $this->regimes->at($employmentId, $d);
                if ($salary === null) {
                    $errors[] = self::error('missing_salary', ['date' => $d]);
                    break;
                }
                if ($regime === null) {
                    $errors[] = self::error('missing_regime', ['date' => $d]);
                    break;
                }
                if ($pack->regime($regime['code']) === null) {
                    $errors[] = self::error('regime_not_supported', ['regime' => $regime['code']]);
                    break;
                }
                if ($salary['salary_basis'] === 'hourly') {
                    $errors[] = self::error('salary_basis_not_supported', ['basis' => $salary['salary_basis']]);
                    break;
                }
                $segEnd = min($end, $salary['valid_to'] ?? $end, $regime['valid_to'] ?? $end);
                $segments[] = ['from' => $d, 'to' => $segEnd, 'calendar_days' => self::days($d, $segEnd), 'days' => self::days($d, $segEnd),
                    'salary' => ['amount' => $salary['amount'], 'currency' => $salary['currency'], 'basis' => $salary['salary_basis']],
                    'regime' => $regime['code'], 'regime_source' => $regime['source']];
                $sources['salary_rows'][$salary['id']] = $salary['valid_from'];
                $sources['regime_rows'][$regime['code'].'@'.$regime['valid_from']] = $regime['source'];
                $regimeAtEnd = $regime['code'];
                $d = CarbonImmutable::parse($segEnd)->addDay()->toDateString();
            }
            $strategy = $regimeAtEnd === null ? null : $pack->regime($regimeAtEnd);
            if ($errors === [] && $strategy !== null) {
                $param = (new PayrollParameters($this->legal, $country, $regimeAtEnd, (string) $emp->legal_entity_id, $end))->resolve($strategy['proration_parameter']);
                if ($param === null || $param['value'] === null || ! is_numeric($param['value']) || BigDecimal::of($param['value'])->isZero()) {
                    $errors[] = self::error('missing_parameter', ['parameter' => $strategy['proration_parameter']]);
                } elseif (($missing = $this->missingRequired($country, $regimeAtEnd, (string) $emp->legal_entity_id, $end, $strategy['required_parameters'])) !== []) {
                    // PAR-5 · RN-PAR-04 · Sin un parámetro requerido del régimen no se calcula; se informa cuál falta.
                    $errors[] = self::error('missing_parameter', ['parameter' => implode(', ', $missing)]);
                } else {
                    $base = BigDecimal::of($param['value']);
                    $sources['proration_parameter'] = $param;
                    $segments = self::fitToBase($segments, $base, $strategy['full_month_equals_base'], $from, $to);
                }
            }
        }

        $variables = [];
        $incidents = [];
        if ($errors === []) {
            /** @var BigDecimal $base */
            $prorated = BigDecimal::zero();
            $relationDays = 0;
            foreach ($segments as $s) {
                $amount = BigDecimal::of($s['salary']['amount']);
                $part = $s['salary']['basis'] === 'daily' ? $amount->multipliedBy($s['days'])
                    : $amount->multipliedBy($s['days'])->dividedBy($base, self::SCALE, RoundingMode::HALF_UP);
                $prorated = $prorated->plus($part);
                $relationDays += $s['days'];
            }
            $last = $segments[count($segments) - 1];
            $summary = $this->absences->summary($employmentId, $start, $end);
            $totals = (array) ($summary['totals'] ?? []);
            $sum = fn (string $key): int => array_sum(array_map(fn ($t) => (int) ($t[$key] ?? 0), (array) ($summary['types'] ?? [])));
            $health = DB::table('employment_labor_regimes')->where('employment_id', $employmentId)->whereNull('deleted_at');
            EffectiveDated::applyAsOf($health, $end);
            $flags = $health->first(['health_scheme', 'has_family_allowance', 'has_risk_insurance']);
            // CPT-5 · Sistema previsional vigente al fin del periodo (afp, onp, none, other): lo usan las condiciones de los aportes.
            $pension = DB::table('employment_pension_affiliations')->where('employment_id', $employmentId);
            EffectiveDated::applyAsOf($pension, $end);
            $pensionSystem = (string) ($pension->value('pension_system') ?? '');
            $sources['absences'] = ['fingerprint' => $summary['fingerprint'] ?? null, 'source' => $summary['source'] ?? null];

            $variables = [
                'sueldo' => BigDecimal::of($last['salary']['amount']),
                'sueldo_tramos' => $prorated,
                'dias_base' => $base,
                'dias_periodo' => BigDecimal::of(self::days($from, $to)),
                'dias_relacion' => BigDecimal::of($relationDays),
                'dias_laborados' => BigDecimal::of((int) ($totals['worked_days'] ?? 0)),
                'dias_remotos' => BigDecimal::of((int) ($totals['remote_days'] ?? 0)),
                'dias_ausencia' => BigDecimal::of((int) ($totals['absence_days'] ?? 0)),
                'dias_ausencia_pagada' => BigDecimal::of($sum('paid_days')),
                'dias_sin_goce' => BigDecimal::of($sum('unpaid_days')),
                'dias_cargo_empleador' => BigDecimal::of($sum('employer_paid_days')),
                'dias_subsidio' => BigDecimal::of($sum('subsidized_days')),
                'horas_ausencia' => BigDecimal::of((string) ($totals['absence_hours'] ?? '0')),
                'fecha_inicio' => $start,
                'fecha_fin' => $end,
                'fecha_ingreso' => $hire,
                'fecha_cese' => $termination ?? '',
                'regimen' => (string) $regimeAtEnd,
                'sistema_salud' => (string) ($flags->health_scheme ?? ''),
                'sistema_pension' => $pensionSystem,
                'tiene_asignacion_familiar' => (bool) ($flags->has_family_allowance ?? false),
                'tiene_seguro_riesgo' => (bool) ($flags->has_risk_insurance ?? false),
            ];
            foreach ($overrides as $name => $value) {
                if (ContextVariables::has($name)) {
                    $variables[$name] = match (ContextVariables::ALL[$name]) {
                        'number' => BigDecimal::of((string) $value),
                        'boolean' => (bool) $value,
                        default => (string) $value,
                    };
                }
            }
            $incidents = $this->incidents($employmentId, $periodId, $extraIncidents);
        }

        return [
            'context' => new CalculationContext($employmentId, $from, $to, $periodKind, $currency, $regimeAtEnd, $variables, $incidents, $segments, $errors, $sources),
            'country' => $country, 'legal_entity_id' => (string) $emp->legal_entity_id, 'decimals' => $pack->decimals(), 'pack_version' => $pack->version(),
        ];
    }

    /**
     * RN-NOM-05 · Días de cada tramo sobre la base del régimen: si la estrategia lo indica, un mes calendario
     * completo cuenta exactamente la base aunque tenga otra cantidad de días; nunca se pasa de la base.
     *
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private static function fitToBase(array $segments, BigDecimal $base, bool $fullMonthEqualsBase, string $from, string $to): array
    {
        $baseDays = $base->toScale(0, RoundingMode::DOWN)->toInt();
        $f = CarbonImmutable::parse($from);
        $wholeMonth = $f->day === 1 && $to === $f->endOfMonth()->toDateString();
        $covered = array_sum(array_column($segments, 'calendar_days'));
        $target = ($fullMonthEqualsBase && $wholeMonth && $covered === $f->daysInMonth) ? $baseDays : min($covered, $baseDays);
        if ($target === $covered || $segments === []) {
            return $segments;
        }
        // Ajusta desde el último tramo hacia atrás sin dejar días negativos.
        $diff = $target - $covered;
        for ($i = count($segments) - 1; $i >= 0 && $diff !== 0; $i--) {
            $new = max(0, (int) $segments[$i]['days'] + $diff);
            $diff -= $new - (int) $segments[$i]['days'];
            $segments[$i]['days'] = $new;
        }

        return $segments;
    }

    /**
     * @param  array<string, array{amount?: string, quantity?: string}>  $extra
     * @return array<string, array{amount: string, quantity: string}>
     */
    private function incidents(string $employmentId, ?string $periodId, array $extra): array
    {
        $out = [];
        if ($periodId !== null) {
            $rows = DB::table('payroll_incidents AS i')->join('payroll_concepts AS c', 'c.id', '=', 'i.payroll_concept_id')
                ->where('i.payroll_period_id', $periodId)->where('i.employment_id', $employmentId)->whereNull('i.deleted_at')
                ->whereIn('i.status', ['pending', 'approved', 'applied'])->orderBy('c.code')
                ->get(['c.code', 'i.amount', 'i.quantity']);
            foreach ($rows as $r) {
                $code = (string) $r->code;
                $cur = $out[$code] ?? ['amount' => '0', 'quantity' => '0'];
                $out[$code] = [
                    'amount' => (string) BigDecimal::of($cur['amount'])->plus(BigDecimal::of((string) ($r->amount ?? '0'))),
                    'quantity' => (string) BigDecimal::of($cur['quantity'])->plus(BigDecimal::of((string) ($r->quantity ?? '0'))),
                ];
            }
        }
        foreach ($extra as $code => $v) {
            $out[$code] = ['amount' => (string) BigDecimal::of((string) ($v['amount'] ?? '0')), 'quantity' => (string) BigDecimal::of((string) ($v['quantity'] ?? '0'))];
        }
        ksort($out);

        return $out;
    }

    private static function days(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
    }

    /**
     * PAR-5 · Parámetros requeridos del régimen sin valor vigente a la fecha (propio de la organización o del país).
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function missingRequired(string $country, string $regime, string $legalEntityId, string $date, array $codes): array
    {
        // Sin el catálogo del país cargado (base recién creada, pruebas del núcleo) solo rigen los parámetros propios.
        if ($codes === [] || ! DB::table('legal_parameters')->where('country_code', $country)->exists()) {
            return [];
        }
        $params = new PayrollParameters($this->legal, $country, $regime, $legalEntityId, $date);

        return array_values(array_filter($codes, function (string $code) use ($params): bool {
            $p = $params->resolve($code);

            return $p === null || $p['value'] === null || $p['value'] === '';
        }));
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{code: string, message: string}
     */
    private static function error(string $code, array $replace = []): array
    {
        return ['code' => $code, 'message' => (string) trans('payroll.context.'.$code, $replace)];
    }
}
