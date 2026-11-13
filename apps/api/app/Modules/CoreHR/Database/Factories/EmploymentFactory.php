<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Relación laboral activa de una persona nueva en una empresa nueva de la organización actual. La empresa
 * se siembra en base: Core HR no usa los modelos de Organización (límites entre módulos).
 *
 * @extends Factory<Employment>
 */
class EmploymentFactory extends Factory
{
    protected $model = Employment::class;

    public function definition(): array
    {
        return [
            'person_id' => fn (): string => Person::factory()->create()->id,
            'legal_entity_id' => fn (): string => self::legalEntity(),
            'employee_code' => 'E'.$this->faker->unique()->numerify('######'),
            'hire_date' => '2025-01-01',
            'status' => 'active',
        ];
    }

    /** Empresa activa de la organización actual (sembrada en base). */
    public static function legalEntity(): string
    {
        $id = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'legal_name' => 'Empresa '.Str::random(6).' S.A.C.',
            'country_code' => 'PE', 'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN',
            'timezone' => 'America/Lima', 'status' => 'active',
        ]);

        return $id;
    }
}
