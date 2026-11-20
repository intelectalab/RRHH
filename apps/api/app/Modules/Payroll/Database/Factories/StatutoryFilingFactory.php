<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\StatutoryFiling;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de prueba de StatutoryFiling (pruebas de aislamiento y de la API).
 *
 * @extends Factory<StatutoryFiling>
 */
class StatutoryFilingFactory extends Factory
{
    protected $model = StatutoryFiling::class;

    public function definition(): array
    {
        return [
            'legal_entity_id' => fn (): string => (string) DB::table('legal_entities')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'legal_name' => 'Declara '.Str::random(6).' S.A.C.', 'country_code' => 'PE',
                'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
            ]),
            'kind' => 'plame',
            'period_code' => '2026'.str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT),
            'status' => 'draft',
        ];
    }
}
