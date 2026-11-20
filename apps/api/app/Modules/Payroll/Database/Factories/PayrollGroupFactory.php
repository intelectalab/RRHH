<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grupo de planilla mensual de una empresa nueva de la organización actual.
 *
 * @extends Factory<PayrollGroup>
 */
class PayrollGroupFactory extends Factory
{
    protected $model = PayrollGroup::class;

    public function definition(): array
    {
        return [
            'code' => 'G'.Str::upper(Str::random(6)),
            'name' => 'Planilla mensual '.Str::random(4),
            'legal_entity_id' => fn (): string => self::legalEntity(),
            'pay_frequency' => 'monthly',
            'currency' => 'PEN',
            'status' => 'active',
        ];
    }

    public static function legalEntity(): string
    {
        $entity = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $entity, 'tenant_id' => TenancyScope::requireTenant(), 'legal_name' => 'Empresa '.Str::random(6).' S.A.C.', 'country_code' => 'PE',
            'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
        ]);

        return $entity;
    }
}
