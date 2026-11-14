<?php

namespace App\Modules\Vacations\Application;

use App\Modules\Vacations\Domain\VacationPolicyStateMachine;
use App\Modules\Vacations\Infrastructure\Models\VacationPolicy;
use App\Modules\Vacations\Infrastructure\Models\VacationPolicyRule;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * VAC-1 · Políticas de vacaciones por empresa, régimen y categoría laboral, con reglas con vigencia.
 *
 * - Las reglas se cambian con EffectiveDated::change: cerrar la fila vigente e insertar otra (regla 2).
 * - Días por año vacíos = los del régimen en los parámetros legales (RN-VAC-01); ningún número legal
 *   vive en el código. Una regla más generosa que la ley se permite; una menor, no (si la ley está
 *   verificada). Si el parámetro legal está «por validar», se advierte en lugar de bloquear.
 * - A una relación le aplica la política activa más específica (empresa, régimen y categoría que
 *   coinciden; los vacíos valen para todos). Sin política, rige la ley del régimen.
 */
final class VacationPolicyCatalog
{
    /** Columnas de regla y su valor por defecto (el mismo que la base). */
    public const RULE_DEFAULTS = [
        'days_per_year' => null,
        'counts_calendar_days' => true,
        'min_days_per_request' => null,
        'allow_advance' => false,
        'max_advance_days' => null,
        'allow_sale' => false,
        'max_sale_days' => null,
        'max_accumulated_periods' => null,
        'requires_hr_validation' => false,
        'approval_workflow_definition_id' => null,
    ];

    private const DECIMALS = ['days_per_year', 'min_days_per_request', 'max_advance_days', 'max_sale_days'];

    public function __construct(
        private readonly LegalParameters $legal,
        private readonly EffectiveDated $validity,
        private readonly VacationPolicyStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $identity  code, name, legal_entity_id, labor_regime_code, job_category_id
     * @param  array<string, mixed>  $rules
     * @return array{policy: VacationPolicy, warnings: list<array{code: string, message: string}>}
     */
    public function create(array $identity, array $rules, string $from): array
    {
        $warnings = $this->validateRules($rules, $identity['labor_regime_code'] ?? null, $from);

        $policy = DB::transaction(function () use ($identity, $rules, $from): VacationPolicy {
            $policy = VacationPolicy::query()->create([
                'code' => $identity['code'], 'name' => $identity['name'],
                'legal_entity_id' => $identity['legal_entity_id'] ?? null,
                'labor_regime_code' => $identity['labor_regime_code'] ?? null,
                'job_category_id' => $identity['job_category_id'] ?? null,
            ]);
            $this->changeRules($policy, $rules, $from);
            $this->audit->record('vacation_policy.created', 'vacation_policy', $policy->id, null, $this->auditable($policy, $from));

            return $policy;
        });

        return ['policy' => $policy, 'warnings' => $warnings];
    }

    /**
     * Cambia el nombre o el alcance (en sitio, auditado) y, si llegan reglas, las registra desde $from.
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $rules
     * @return array{policy: VacationPolicy, warnings: list<array{code: string, message: string}>}
     */
    public function update(VacationPolicy $policy, array $identity, array $rules, string $from): array
    {
        $regime = array_key_exists('labor_regime_code', $identity) ? $identity['labor_regime_code'] : $policy->labor_regime_code;
        $warnings = $rules === [] ? [] : $this->validateRules($rules + $this->currentRuleValues($policy, $from), $regime, $from);

        DB::transaction(function () use ($policy, $identity, $rules, $from): void {
            $before = $this->auditable($policy, $from);
            $policy->fill(array_intersect_key($identity, array_flip(['name', 'legal_entity_id', 'labor_regime_code', 'job_category_id'])));
            if ($policy->isDirty()) {
                $policy->save();
            }
            if ($rules !== []) {
                $this->changeRules($policy, $rules, $from);
            }
            $this->audit->record('vacation_policy.updated', 'vacation_policy', $policy->id, $before, $this->auditable($policy, $from));
        });

        return ['policy' => $policy->refresh(), 'warnings' => $warnings];
    }

    public function transition(VacationPolicy $policy, string $action, ?string $reason): VacationPolicy
    {
        if (! $this->machine->can($policy->status, $action)) {
            throw new InvalidTransitionException($policy->status, $this->machine->allowedActions($policy->status));
        }
        $from = $policy->status;
        $policy->status = $this->machine->getNextState($policy->status, $action);
        $policy->save();
        $this->audit->record('vacation_policy.'.$action, 'vacation_policy', $policy->id, ['status' => $from], ['status' => $policy->status], $reason);

        return $policy;
    }

    public function rulesAt(VacationPolicy $policy, string $asOf): ?VacationPolicyRule
    {
        /** @var VacationPolicyRule|null */
        return VacationPolicyRule::query()->where('vacation_policy_id', $policy->id)->asOf($asOf)->first();
    }

    /**
     * @return list<VacationPolicyRule>
     */
    public function history(VacationPolicy $policy): array
    {
        /** @var list<VacationPolicyRule> */
        return array_values(VacationPolicyRule::query()->where('vacation_policy_id', $policy->id)->orderByDesc('valid_from')->get()->all());
    }

    /**
     * Política y reglas que aplican a una relación a la fecha, con los días por año efectivos y su origen.
     *
     * @param  array{legal_entity_id: string, labor_regime_code: string|null, job_category_id: string|null}  $context
     * @return array{policy_id: string|null, policy_code: string|null, policy_name: string|null, source: string, days_per_year: string|null, pending_validation: bool, rules: array<string, mixed>, legal: array<string, mixed>}
     */
    public function resolve(array $context, string $asOf): array
    {
        $best = null;
        $bestScore = -1;
        $candidates = VacationPolicy::query()->where('status', 'active')->orderBy('created_at')->get();
        foreach ($candidates as $p) {
            if (($p->legal_entity_id !== null && $p->legal_entity_id !== $context['legal_entity_id'])
                || ($p->labor_regime_code !== null && $p->labor_regime_code !== $context['labor_regime_code'])
                || ($p->job_category_id !== null && $p->job_category_id !== $context['job_category_id'])) {
                continue;
            }
            $score = ($p->legal_entity_id !== null ? 1 : 0) + ($p->labor_regime_code !== null ? 1 : 0) + ($p->job_category_id !== null ? 2 : 0);
            if ($score > $bestScore && $this->rulesAt($p, $asOf) !== null) {
                $best = $p;
                $bestScore = $score;
            }
        }
        $rule = $best === null ? null : $this->rulesAt($best, $asOf);
        $legal = $this->legalLimits($context['labor_regime_code'], $asOf);
        $rules = $rule === null ? self::RULE_DEFAULTS : $this->presentRule($rule);
        $own = $rules['days_per_year'] ?? null;
        $days = $own ?? $legal['days_per_year']['value'];

        return [
            'policy_id' => $best?->id,
            'policy_code' => $best?->code,
            'policy_name' => $best?->name,
            'source' => $own !== null ? 'policy' : 'legal',
            'days_per_year' => $days === null ? null : (string) $days,
            'pending_validation' => $own === null && $legal['days_per_year']['pending_validation'],
            'rules' => $rules,
            'legal' => $legal,
        ];
    }

    /**
     * Límites legales del régimen a la fecha (RN-VAC-01, 04, 05, 09), cada uno con su estado de validación.
     *
     * @return array<string, array{parameter_code: string, value: string|null, verified: bool, pending_validation: bool, legal_source: string|null}>
     */
    public function legalLimits(?string $regime, string $asOf): array
    {
        $country = $this->country();
        $out = [];
        foreach (self::legalCodes($country) as $key => $code) {
            $p = $this->legal->at($country, $code, $asOf, $regime);
            $value = $p === null || is_array($p['value']) ? null : $p['value'];
            $out[$key] = [
                'parameter_code' => $code,
                'value' => $value,
                'verified' => $p !== null && $p['verified'],
                'pending_validation' => $p === null || $p['pending_validation'],
                'legal_source' => $p['legal_source'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Códigos de los parámetros legales que usa el módulo, según el paquete del país.
     *
     * @return array<string, string>
     */
    public static function legalCodes(string $country): array
    {
        $pkg = self::countryPackage($country);
        /** @var array<string, string> $codes */
        $codes = $pkg['parametros'] ?? [];

        return [
            'days_per_year' => $codes['dias_por_anio'] ?? 'vacaciones_dias',
            'enjoy_within_months' => $codes['plazo_goce_meses'] ?? 'vacaciones_plazo_goce_meses',
            'record_days_6' => $codes['record_jornada_6'] ?? 'vacaciones_record_dias_jornada_6',
            'record_days_5' => $codes['record_jornada_5'] ?? 'vacaciones_record_dias_jornada_5',
            'max_sale_days' => $codes['venta_max_dias'] ?? 'vacaciones_venta_max_dias',
            'min_fraction_days' => $codes['fraccion_minima_dias'] ?? 'vacaciones_fraccion_minima_dias',
            'max_advance_days' => $codes['adelanto_max_dias'] ?? 'vacaciones_adelanto_max_dias',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function countryPackage(string $country): array
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [];
        }
        $file = base_path('packages/country-'.strtolower($country).'/data/vacaciones.php');
        if (! is_file($file)) {
            return [];
        }

        /** @var array<string, mixed> */
        return require $file;
    }

    /**
     * Reglas frente a la ley del régimen: lo verificado bloquea (422), lo «por validar» advierte.
     *
     * @param  array<string, mixed>  $rules
     * @return list<array{code: string, message: string}>
     */
    public function validateRules(array $rules, ?string $regime, string $from): array
    {
        $legal = $this->legalLimits($regime, $from);
        $errors = [];
        $warnings = [];
        $checks = [
            // [regla, límite legal, 'min' = la regla no puede ser menor | 'max' = no puede ser mayor]
            ['days_per_year', 'days_per_year', 'min'],
            ['max_sale_days', 'max_sale_days', 'max'],
            ['min_days_per_request', 'min_fraction_days', 'min'],
            ['max_advance_days', 'max_advance_days', 'max'],
        ];
        foreach ($checks as [$field, $limit, $kind]) {
            $value = $rules[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($regime === null) {
                continue;
            }
            $l = $legal[$limit];
            if ($l['value'] === null || $l['pending_validation']) {
                $warnings[] = ['code' => 'legal_pending_validation', 'message' => (string) trans('vacations.legal_pending', ['field' => $field, 'parameter' => $l['parameter_code']])];

                continue;
            }
            $cmp = BigDecimal::of((string) $value)->compareTo(BigDecimal::of((string) $l['value']));
            if (($kind === 'min' && $cmp < 0) || ($kind === 'max' && $cmp > 0)) {
                $errors[] = ['field' => $field, 'rule' => 'legal_'.$kind, 'message' => (string) trans('vacations.legal_'.$kind, ['value' => $l['value'], 'parameter' => $l['parameter_code']])];
            }
        }
        if (($rules['allow_sale'] ?? false) === false && ($rules['max_sale_days'] ?? null) !== null) {
            $errors[] = ['field' => 'max_sale_days', 'rule' => 'sale_disabled', 'message' => (string) trans('vacations.sale_disabled')];
        }
        if (($rules['allow_advance'] ?? false) === false && ($rules['max_advance_days'] ?? null) !== null) {
            $errors[] = ['field' => 'max_advance_days', 'rule' => 'advance_disabled', 'message' => (string) trans('vacations.advance_disabled')];
        }
        if ($errors !== []) {
            throw new AyniException('validation_failed', 422, (string) trans('vacations.rules_invalid'), $errors);
        }
        if (($rules['days_per_year'] ?? null) === null && $regime !== null && $legal['days_per_year']['pending_validation']) {
            $warnings[] = ['code' => 'legal_days_pending', 'message' => (string) trans('vacations.legal_days_pending', ['parameter' => $legal['days_per_year']['parameter_code']])];
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(VacationPolicy $p, string $asOf): array
    {
        $rule = $this->rulesAt($p, $asOf) ?? VacationPolicyRule::query()->where('vacation_policy_id', $p->id)->orderByDesc('valid_from')->first();

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'legal_entity_id' => $p->legal_entity_id,
            'legal_entity_name' => $p->legal_entity_id === null ? null : DB::table('legal_entities')->where('id', $p->legal_entity_id)->value('legal_name'),
            'labor_regime_code' => $p->labor_regime_code,
            'job_category_id' => $p->job_category_id,
            'job_category_name' => $p->job_category_id === null ? null : DB::table('job_categories')->where('id', $p->job_category_id)->value('name'),
            'status' => $p->status,
            'allowed_actions' => $this->machine->allowedActions($p->status),
            'rules' => $rule instanceof VacationPolicyRule ? $this->presentRule($rule) : null,
            'legal' => $this->legalLimits($p->labor_regime_code, $asOf),
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRule(VacationPolicyRule $r): array
    {
        $out = ['id' => $r->id];
        foreach (array_keys(self::RULE_DEFAULTS) as $column) {
            $value = $r->getAttribute($column);
            $out[$column] = in_array($column, self::DECIMALS, true) && $value !== null ? self::number((string) $value) : $value;
        }
        $out['valid_from'] = substr((string) $r->getRawOriginal('valid_from'), 0, 10);
        $raw = $r->getRawOriginal('valid_to');
        $out['valid_to'] = $raw === null ? null : substr((string) $raw, 0, 10);

        return $out;
    }

    public function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function changeRules(VacationPolicy $policy, array $changes, string $from): string
    {
        $values = $this->currentRuleValues($policy, $from);
        foreach (self::RULE_DEFAULTS as $column => $default) {
            if (array_key_exists($column, $changes)) {
                $values[$column] = $changes[$column];
            }
        }
        $values['tenant_id'] = TenancyScope::requireTenant();
        $sameDay = $from >= now()->toDateString() ? EffectiveDated::SAME_DAY_REPLACE : EffectiveDated::SAME_DAY_REJECT;

        return $this->validity->change('vacation_policy_rules', ['vacation_policy_id' => $policy->id], $values, $from, $sameDay);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentRuleValues(VacationPolicy $policy, string $from): array
    {
        $current = $this->rulesAt($policy, $from);
        $values = [];
        foreach (self::RULE_DEFAULTS as $column => $default) {
            $values[$column] = $current !== null ? $current->getAttribute($column) : $default;
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(VacationPolicy $policy, string $asOf): array
    {
        $rule = $this->rulesAt($policy, $asOf);

        return [
            'code' => $policy->code, 'name' => $policy->name, 'status' => $policy->status,
            'legal_entity_id' => $policy->legal_entity_id, 'labor_regime_code' => $policy->labor_regime_code,
            'job_category_id' => $policy->job_category_id,
            'rules' => $rule === null ? null : $this->presentRule($rule),
        ];
    }

    private static function number(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
