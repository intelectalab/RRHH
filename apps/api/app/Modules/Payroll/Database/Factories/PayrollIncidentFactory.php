<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollIncident;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Incidencia manual pendiente de un trabajador de la empresa del periodo.
 *
 * @extends Factory<PayrollIncident>
 */
class PayrollIncidentFactory extends Factory
{
    protected $model = PayrollIncident::class;

    public function definition(): array
    {
        return [
            'payroll_period_id' => fn (): string => (string) PayrollPeriod::factory()->create()->id,
            'employment_id' => fn (array $a): string => self::employment((string) $a['payroll_period_id']),
            'payroll_concept_id' => fn (): string => self::concept(),
            'amount' => '150.00',
            'currency' => 'PEN',
            'source' => 'manual',
            'status' => 'pending',
        ];
    }

    public static function employment(string $periodId): string
    {
        $tenant = TenancyScope::requireTenant();
        $groupId = (string) DB::table('payroll_periods')->where('id', $periodId)->value('payroll_group_id');
        $entity = (string) PayrollGroup::query()->whereKey($groupId)->value('legal_entity_id');
        $person = (string) DB::table('people')->insertGetId(['tenant_id' => $tenant, 'first_names' => 'Persona', 'paternal_surname' => Str::random(6)]);

        return (string) DB::table('employments')->insertGetId([
            'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => $entity,
            'employee_code' => 'I'.random_int(100000, 999999), 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);
    }

    public static function concept(): string
    {
        return (string) DB::table('payroll_concepts')->insertGetId([
            'tenant_id' => TenancyScope::requireTenant(), 'code' => 'BONO'.Str::upper(Str::random(4)), 'name' => 'Bono variable', 'kind' => 'earning',
            'calculation_method' => 'input', 'sort_order' => 10,
        ]);
    }
}
