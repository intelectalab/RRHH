<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollRun;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de prueba de PayrollRun (pruebas de aislamiento y de la API). Crea su empresa, grupo y periodo por
 * tablas (el módulo de nómina no usa las capas internas de otros módulos).
 *
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public static function legalEntity(): string
    {
        $tenant = TenancyScope::requireTenant();

        return (string) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant, 'legal_name' => 'Planilla '.Str::random(6).' S.A.C.', 'country_code' => 'PE',
            'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
        ]);
    }

    public static function employment(): string
    {
        $tenant = TenancyScope::requireTenant();
        $person = (string) DB::table('people')->insertGetId(['tenant_id' => $tenant, 'first_names' => 'Persona', 'paternal_surname' => Str::random(6)]);

        return (string) DB::table('employments')->insertGetId([
            'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => self::legalEntity(),
            'employee_code' => 'P'.random_int(100000, 999999), 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);
    }

    /**
     * Periodo abierto de un grupo de planilla de una empresa nueva.
     */
    public static function period(): string
    {
        $tenant = TenancyScope::requireTenant();
        $group = (string) DB::table('payroll_groups')->insertGetId(['tenant_id' => $tenant, 'code' => 'G'.Str::random(6), 'name' => 'Mensual',
            'legal_entity_id' => self::legalEntity(), 'currency' => 'PEN']);

        return (string) DB::table('payroll_periods')->insertGetId(['tenant_id' => $tenant, 'payroll_group_id' => $group, 'period_year' => 2026,
            'period_number' => random_int(1, 12), 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);
    }

    public function definition(): array
    {
        return [
            'payroll_period_id' => fn (): string => self::period(),
            'run_number' => 1,
            'kind' => 'calculation',
            'status' => 'completed',
            'employments_total' => 0,
        ];
    }
}
