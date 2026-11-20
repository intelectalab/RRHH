<?php

namespace Tests\Support;

use App\Shared\Security\FieldCipher;
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
     * @param  array{code: string, kind: string, formula?: string|null, condition?: string|null, sort_order?: int, regimes?: list<string>|null, kinds?: list<string>|null, affects?: list<string>}  $c
     */
    public static function concept(array $c, string $validFrom = '2020-01-01'): string
    {
        $tenant = TenancyScope::requireTenant();
        $id = (string) DB::table('payroll_concepts')->insertGetId([
            'tenant_id' => $tenant, 'code' => $c['code'], 'name' => 'Concepto '.$c['code'], 'kind' => $c['kind'],
            'calculation_method' => isset($c['formula']) ? 'formula' : 'input', 'sort_order' => $c['sort_order'] ?? 0,
        ]);
        $version = (string) DB::table('payroll_concept_versions')->insertGetId([
            'tenant_id' => $tenant, 'payroll_concept_id' => $id, 'formula' => $c['formula'] ?? null, 'valid_from' => $validFrom,
            'condition' => $c['condition'] ?? null,
            'applies_to_regimes' => isset($c['regimes']) ? '{'.implode(',', $c['regimes']).'}' : null,
            'applies_to_period_kinds' => isset($c['kinds']) ? '{'.implode(',', $c['kinds']).'}' : null,
        ]);
        // CPT-3 · Bases a las que suma («quinta:ordinary» indica además su naturaleza en la proyección).
        foreach ((array) ($c['affects'] ?? []) as $a) {
            [$base, $kind] = array_pad(explode(':', (string) $a, 2), 2, null);
            DB::table('payroll_concept_affectations')->insert(['tenant_id' => $tenant, 'payroll_concept_version_id' => $version,
                'base_code' => $base, 'affects' => true, 'projection_kind' => $kind]);
        }

        return $id;
    }

    /**
     * Valor propio en JSON (escalas y tablas) de un parámetro, fijado para la prueba.
     *
     * @param  array<int|string, mixed>  $value
     */
    public static function parameterJson(string $code, array $value, string $validFrom = '2020-01-01'): void
    {
        DB::table('legal_parameter_overrides')->insert(['tenant_id' => TenancyScope::requireTenant(), 'parameter_code' => $code,
            'value_json' => json_encode($value), 'reason' => 'Valor fijado para la prueba', 'valid_from' => $validFrom]);
    }

    /**
     * PER-5 · Parámetros de la retención de quinta fijados para la prueba (UIT y escala del caso, no constantes).
     */
    public static function incomeTaxParameters(string $uit, string $validFrom = '2020-01-01'): void
    {
        self::parameter('uit', $uit, $validFrom);
        self::parameter('quinta_deduccion_uit', '7', $validFrom);
        self::parameterJson('quinta_escala', [['hasta_uit' => 5, 'tasa' => 8], ['hasta_uit' => 20, 'tasa' => 14], ['hasta_uit' => 35, 'tasa' => 17],
            ['hasta_uit' => 45, 'tasa' => 20], ['hasta_uit' => null, 'tasa' => 30]], $validFrom);
        self::parameterJson('quinta_divisores', ['1' => 12, '2' => 12, '3' => 12, '4' => 9, '5' => 8, '6' => 8, '7' => 8, '8' => 5, '9' => 4, '10' => 4, '11' => 4, '12' => 1], $validFrom);
        self::parameterJson('quinta_cortes_retenciones', ['1' => 0, '2' => 0, '3' => 0, '4' => 3, '5' => 4, '6' => 4, '7' => 4, '8' => 7, '9' => 8, '10' => 8, '11' => 8, '12' => 11], $validFrom);
    }

    /** BSO-2 · Cuenta de CTS activa (entidad depositaria, COL-8) de la relación laboral. */
    public static function ctsAccount(string $employment, string $bank = 'BCP', string $validFrom = '2020-01-01'): string
    {
        return (string) DB::table('person_bank_accounts')->insertGetId(['tenant_id' => TenancyScope::requireTenant(),
            'person_id' => DB::table('employments')->where('id', $employment)->value('person_id'), 'employment_id' => $employment, 'purpose' => 'cts',
            'bank_code' => $bank, 'account_type' => 'savings', 'account_number_enc' => FieldCipher::encrypt('19100000000099'),
            'currency' => 'PEN', 'status' => 'active', 'valid_from' => $validFrom]);
    }

    public static function parameter(string $code, string $value, string $validFrom = '2020-01-01'): void
    {
        DB::table('legal_parameter_overrides')->insert(['tenant_id' => TenancyScope::requireTenant(), 'parameter_code' => $code,
            'value_numeric' => $value, 'reason' => 'Valor fijado para la prueba', 'valid_from' => $validFrom]);
    }
}
