<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Modules\Payroll\Infrastructure\RegimeResolver;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NOM-2 · Regímenes laborales del país con la estrategia que les da el paquete, y el régimen de cada
 * relación con vigencia: heredado de la empresa al ingresar y modificable por trabajador con fecha efectiva
 * (se cierra el tramo vigente y se abre otro; nada se sobrescribe, regla 2).
 */
final class PayrollRegimes
{
    public function __construct(private readonly RegimeResolver $resolver, private readonly EffectiveDated $validity, private readonly AuditLogger $audit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $country = $this->country();
        $pack = new DataPayrollCountryPack($country);

        return array_values(DB::table('labor_regimes')->where('country_code', $country)->orderBy('code')->get(['id', 'code', 'country_code', 'name', 'kind'])
            ->map(fn ($r) => $this->present($r, $pack))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $country = $this->country();
        $row = DB::table('labor_regimes')->where('country_code', $country)->where('id', $id)->first(['id', 'code', 'country_code', 'name', 'kind']);
        if ($row === null) {
            throw new AyniException('not_found', 404);
        }

        return $this->present($row, new DataPayrollCountryPack($country));
    }

    /**
     * @return array<string, mixed>
     */
    public function forEmployment(string $employmentId, string $asOf): array
    {
        $emp = $this->employment($employmentId);
        $current = $this->resolver->at($employmentId, $asOf);
        $pack = new DataPayrollCountryPack($this->country());

        return [
            'employment_id' => $employmentId, 'as_of' => $asOf, 'legal_entity_id' => (string) $emp->legal_entity_id,
            'labor_regime_code' => $current['code'] ?? null, 'source' => $current['source'] ?? null,
            'valid_from' => $current['valid_from'] ?? null, 'valid_to' => $current['valid_to'] ?? null,
            'supported' => $current !== null && $pack->regime($current['code']) !== null,
            'history' => $this->resolver->history($employmentId),
        ];
    }

    /**
     * @param  array{labor_regime_code: string, valid_from: string, reason: string}  $input
     * @return array<string, mixed>
     */
    public function change(string $employmentId, array $input): array
    {
        $emp = $this->employment($employmentId);
        $code = $input['labor_regime_code'];
        $from = EffectiveDated::day($input['valid_from']);
        $country = $this->country();
        if (! DB::table('labor_regimes')->where('country_code', $country)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['labor_regime_code' => [(string) trans('payroll.regime_unknown')]]);
        }
        if ($from < substr((string) $emp->hire_date, 0, 10)) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('payroll.regime_before_hire')]]);
        }
        $strategy = (new DataPayrollCountryPack($country))->regime($code);
        if ($strategy !== null && $strategy['requires_accreditation'] && ! $this->resolver->entityAccredited((string) $emp->legal_entity_id, $code, $from)) {
            // RN-NOM-19 · Sin acreditación vigente de la empresa no se asigna micro ni pequeña empresa.
            throw new AyniException('regime_not_accredited', 422, (string) trans('payroll.regime_not_accredited'));
        }
        $before = $this->resolver->at($employmentId, $from);

        DB::transaction(function () use ($employmentId, $code, $from, $before, $input): void {
            $current = $this->validity->rowAt('employment_labor_regimes', ['employment_id' => $employmentId], $from);
            $this->validity->change('employment_labor_regimes', ['tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $employmentId], [
                'labor_regime_code' => $code,
                'health_scheme' => $current->health_scheme ?? 'essalud',
                'health_provider_id' => $current->health_provider_id ?? null,
                'health_plan_code' => $current->health_plan_code ?? null,
                'has_risk_insurance' => $current->has_risk_insurance ?? false,
                'has_family_allowance' => $current->has_family_allowance ?? false,
            ], $from);
            $this->audit->record('employment.labor_regime_changed', 'employment', $employmentId, $before, ['labor_regime_code' => $code, 'valid_from' => $from], $input['reason']);
        });

        return $this->forEmployment($employmentId, $from);
    }

    /**
     * @param  \stdClass  $r
     * @return array<string, mixed>
     */
    private function present(object $r, DataPayrollCountryPack $pack): array
    {
        $strategy = $pack->regime((string) $r->code);

        return [
            'id' => (string) $r->id, 'code' => (string) $r->code, 'country' => (string) $r->country_code, 'name' => (string) $r->name,
            'kind' => (string) $r->kind,
            'rules' => $strategy === null ? (object) [] : [
                'proration_parameter' => $strategy['proration_parameter'], 'full_month_equals_base' => $strategy['full_month_equals_base'],
                'requires_accreditation' => $strategy['requires_accreditation'], 'pending_validation' => $strategy['pending_validation'],
                'pack_version' => $pack->version(),
            ],
            'status' => 'active', 'supported' => $strategy !== null,
        ];
    }

    private function employment(string $employmentId): \stdClass
    {
        /** @var \stdClass|null $emp */
        $emp = DB::table('employments')->where('id', $employmentId)->whereNull('deleted_at')->first(['id', 'legal_entity_id', 'hire_date']);
        if ($emp === null) {
            throw new AyniException('not_found', 404);
        }

        return $emp;
    }

    private function country(): string
    {
        return (string) DB::table('current_tenant')->value('country_code');
    }
}
