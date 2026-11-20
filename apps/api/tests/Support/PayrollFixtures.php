<?php

namespace Tests\Support;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NOM · Datos de prueba del motor de nómina: empresa con régimen, relación laboral con sueldos con vigencia,
 * conceptos con su versión y valores propios de parámetros. Se usan dentro de TenancyScope::runAs.
 */
final class PayrollFixtures
{
    public static function entity(string $regime = 'pe_general', string $accreditation = 'not_required'): string
    {
        $tenant = TenancyScope::requireTenant();
        $entity = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $entity, 'tenant_id' => $tenant, 'legal_name' => 'Planilla '.Str::random(6).' S.A.C.', 'country_code' => 'PE',
            'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
        ]);
        DB::table('legal_entity_regimes')->insert(['tenant_id' => $tenant, 'legal_entity_id' => $entity, 'labor_regime_code' => $regime,
            'accreditation_status' => $accreditation, 'valid_from' => '2020-01-01']);

        return $entity;
    }

    /**
     * @param  list<array{amount: string, valid_from: string, valid_to?: string|null}>  $compensations
     */
    public static function employment(string $entity, string $hireDate, array $compensations, ?string $ownRegime = null): string
    {
        $tenant = TenancyScope::requireTenant();
        $person = (string) DB::table('people')->insertGetId(['tenant_id' => $tenant, 'first_names' => 'Persona', 'paternal_surname' => Str::random(6)]);
        $employment = (string) DB::table('employments')->insertGetId([
            'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => $entity,
            'employee_code' => 'P'.random_int(100000, 999999), 'hire_date' => $hireDate, 'status' => 'active',
        ]);
        foreach ($compensations as $c) {
            DB::table('employment_compensations')->insert(['tenant_id' => $tenant, 'employment_id' => $employment, 'base_salary' => $c['amount'],
                'currency' => 'PEN', 'valid_from' => $c['valid_from'], 'valid_to' => $c['valid_to'] ?? null]);
        }
        if ($ownRegime !== null) {
            DB::table('employment_labor_regimes')->insert(['tenant_id' => $tenant, 'employment_id' => $employment, 'labor_regime_code' => $ownRegime, 'valid_from' => $hireDate]);
        }

        return $employment;
    }

    /**
     * @param  array{code: string, kind: string, formula?: string|null, sort_order?: int, regimes?: list<string>|null}  $c
     */
    public static function concept(array $c, string $validFrom = '2020-01-01'): string
    {
        $tenant = TenancyScope::requireTenant();
        $id = (string) DB::table('payroll_concepts')->insertGetId([
            'tenant_id' => $tenant, 'code' => $c['code'], 'name' => 'Concepto '.$c['code'], 'kind' => $c['kind'],
            'calculation_method' => isset($c['formula']) ? 'formula' : 'input', 'sort_order' => $c['sort_order'] ?? 0,
        ]);
        DB::table('payroll_concept_versions')->insert([
            'tenant_id' => $tenant, 'payroll_concept_id' => $id, 'formula' => $c['formula'] ?? null, 'valid_from' => $validFrom,
            'applies_to_regimes' => isset($c['regimes']) ? '{'.implode(',', $c['regimes']).'}' : null,
        ]);

        return $id;
    }

    public static function parameter(string $code, string $value, string $validFrom = '2020-01-01'): void
    {
        DB::table('legal_parameter_overrides')->insert(['tenant_id' => TenancyScope::requireTenant(), 'parameter_code' => $code,
            'value_numeric' => $value, 'reason' => 'Valor fijado para la prueba', 'valid_from' => $validFrom]);
    }
}
