<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CON-2 · CON-3 · Reglas de las condiciones del contrato, leídas de los parámetros legales con vigencia
 * (regla 4: ningún plazo legal en el código):
 *
 * - RN-CON-01: un tipo a modalidad exige fecha de fin y causa objetiva escrita.
 * - RN-CON-07: el periodo de prueba no supera el máximo del tipo (parámetro del paquete de país).
 * - RN-CON-02: la suma de plazos de una misma modalidad para la persona en la empresa no supera su máximo.
 * - RN-CON-03: la suma de todos los contratos a modalidad no supera el tope total.
 *
 * Un parámetro sin valor verificado no bloquea: se devuelve como advertencia «por validar» (kit/docs/19).
 */
final class ContractTerms
{
    /** Tope total de contratos a modalidad (RN-CON-03). */
    public const TOTAL_CAP_PARAMETER = 'contrato_modalidad_tope_acumulado_anios';

    public function __construct(private readonly LegalParameters $legal) {}

    /**
     * Valida tipo, fechas, causa y periodo de prueba de un contrato. Devuelve advertencias.
     *
     * @param  array{contract_type_id: string, starts_on: string, ends_on: ?string, objective_cause: ?string, trial_period_ends_on: ?string, weekly_hours: ?string}  $terms
     * @return list<array{code: string, message: string}>
     */
    public function validate(array $terms, string $country): array
    {
        $type = DB::table('contract_types')->where('id', $terms['contract_type_id'])->whereNull('deleted_at')->first();
        if ($type === null) {
            throw ValidationException::withMessages(['contract_type_id' => [(string) trans('contracts.contracts.type_invalid')]]);
        }
        $start = $terms['starts_on'];
        $end = $terms['ends_on'];
        $errors = [];
        if ((bool) $type->requires_end_date && $end === null) {
            $errors['ends_on'] = (string) trans('contracts.contracts.end_required');
        }
        if ($end !== null && $end < $start) {
            $errors['ends_on'] = (string) trans('contracts.contracts.end_before_start');
        }
        if ((bool) $type->requires_objective_cause && trim((string) $terms['objective_cause']) === '') {
            $errors['objective_cause'] = (string) trans('contracts.contracts.cause_required');
        }
        if ($terms['weekly_hours'] !== null && ((float) $terms['weekly_hours'] <= 0 || (float) $terms['weekly_hours'] > 168)) {
            $errors['weekly_hours'] = (string) trans('contracts.contracts.weekly_hours_invalid');
        }
        $warnings = [];
        $trial = $terms['trial_period_ends_on'];
        if ($trial !== null) {
            if ($trial < $start || ($end !== null && $trial > $end)) {
                $errors['trial_period_ends_on'] = (string) trans('contracts.contracts.trial_out_of_range');
            } else {
                $limit = $this->limit($country, $type->trial_period_parameter_code, $start);
                if ($limit['pending']) {
                    $warnings[] = ['code' => 'trial_period_pending_validation', 'message' => (string) trans('contracts.contracts.trial_pending', ['parameter' => (string) $type->trial_period_parameter_code])];
                } elseif ($limit['max_end'] !== null && $trial > $limit['max_end']) {
                    $errors['trial_period_ends_on'] = (string) trans('contracts.contracts.trial_too_long', ['max' => $limit['max_end']]);
                }
            }
        }
        if ($end !== null && $type->max_duration_parameter_code !== null) {
            $limit = $this->limit($country, (string) $type->max_duration_parameter_code, $start);
            if ($limit['pending']) {
                $warnings[] = ['code' => 'max_duration_pending_validation', 'message' => (string) trans('contracts.contracts.max_pending', ['parameter' => (string) $type->max_duration_parameter_code])];
            } elseif ($limit['max_end'] !== null && $end > $limit['max_end']) {
                $errors['ends_on'] = (string) trans('contracts.contracts.too_long', ['max' => $limit['max_end']]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $errors));
        }

        return $warnings;
    }

    /**
     * RN-CON-02 · RN-CON-03 · Tiempo acumulado de la persona en la empresa si se firma un contrato más
     * (desde $start hasta $end). Lanza 422 contract_limit_exceeded si supera un tope verificado.
     *
     * @return array{modality_days: int, modality_max_days: int|null, total_days: int, total_max_days: int|null, pending_validation: list<string>, explanation: string}
     */
    public function accumulation(string $employmentId, string $contractTypeId, string $start, ?string $end, ?string $excludeContractId, string $country, bool $enforce): array
    {
        $type = DB::table('contract_types')->where('id', $contractTypeId)->first();
        $employment = DB::table('employments')->where('id', $employmentId)->first(['person_id', 'legal_entity_id']);
        $empty = ['modality_days' => 0, 'modality_max_days' => null, 'total_days' => 0, 'total_max_days' => null, 'pending_validation' => [], 'explanation' => ''];
        if ($type === null || $employment === null || $type->category !== 'fixed_term') {
            return $empty;
        }
        // Todos los contratos firmados de la persona con la misma empresa (también de relaciones anteriores).
        $rows = DB::table('contracts as c')->join('employments as e', 'e.id', '=', 'c.employment_id')
            ->join('contract_types as t', 't.id', '=', 'c.contract_type_id')
            ->where('e.person_id', $employment->person_id)->where('e.legal_entity_id', $employment->legal_entity_id)
            ->whereNull('c.deleted_at')->whereIn('c.status', ContractStateMachine::COUNTED)->where('t.category', 'fixed_term')
            ->when($excludeContractId !== null, fn ($q) => $q->where('c.id', '<>', $excludeContractId))
            ->get(['c.valid_from', 'c.valid_to', 'c.contract_type_id', 't.code']);
        $days = fn (string $from, ?string $to): int => $to === null ? 0 : (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $new = $days($start, $end);
        $modality = $new;
        $total = $new;
        $first = $start;
        foreach ($rows as $r) {
            $d = $days((string) $r->valid_from, $r->valid_to === null ? null : (string) $r->valid_to);
            $total += $d;
            if ($r->code === $type->code) {
                $modality += $d;
            }
            $first = min($first, (string) $r->valid_from);
        }
        $pending = [];
        $modalityMax = null;
        if ($type->max_duration_parameter_code !== null) {
            $limit = $this->limit($country, (string) $type->max_duration_parameter_code, $first);
            $limit['pending'] ? $pending[] = (string) $type->max_duration_parameter_code : $modalityMax = $limit['max_days'];
        }
        $totalLimit = $this->limit($country, self::TOTAL_CAP_PARAMETER, $first);
        $totalMax = null;
        $totalLimit['pending'] ? $pending[] = self::TOTAL_CAP_PARAMETER : $totalMax = $totalLimit['max_days'];

        $result = [
            'modality_days' => $modality, 'modality_max_days' => $modalityMax, 'total_days' => $total, 'total_max_days' => $totalMax,
            'pending_validation' => $pending,
            'explanation' => (string) trans('contracts.contracts.accumulation_text', [
                'modality' => $modality, 'modality_max' => $modalityMax ?? '—', 'total' => $total, 'total_max' => $totalMax ?? '—',
            ]),
        ];
        if ($enforce && (($modalityMax !== null && $modality > $modalityMax) || ($totalMax !== null && $total > $totalMax))) {
            throw new AyniException('contract_limit_exceeded', 422, (string) trans('contracts.contracts.limit_exceeded', [
                'modality' => $modality, 'modality_max' => $modalityMax ?? '—', 'total' => $total, 'total_max' => $totalMax ?? '—',
            ]), [], $result);
        }

        return $result;
    }

    /**
     * Límite de un parámetro de plazo (años o meses) contado desde $from.
     *
     * @return array{pending: bool, max_end: string|null, max_days: int|null}
     */
    private function limit(string $country, ?string $code, string $from): array
    {
        if ($code === null || $code === '') {
            return ['pending' => false, 'max_end' => null, 'max_days' => null];
        }
        $p = $this->legal->at($country, $code, $from);
        if ($p === null || $p['pending_validation'] || ! is_numeric($p['value'])) {
            return ['pending' => true, 'max_end' => null, 'max_days' => null];
        }
        $start = CarbonImmutable::parse($from);
        $n = (int) $p['value'];
        $end = match ($p['unit']) {
            'years' => $start->addYears($n),
            'months', 'months_per_year' => $start->addMonths($n),
            'days' => $start->addDays($n),
            default => null,
        };
        if ($end === null) {
            return ['pending' => true, 'max_end' => null, 'max_days' => null];
        }
        $end = $end->subDay();

        return ['pending' => false, 'max_end' => $end->toDateString(), 'max_days' => (int) $start->diffInDays($end) + 1];
    }
}
