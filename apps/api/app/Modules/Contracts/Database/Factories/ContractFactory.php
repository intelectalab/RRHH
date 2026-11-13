<?php

namespace App\Modules\Contracts\Database\Factories;

use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractType;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contrato vigente a plazo indeterminado de una relación laboral nueva. La persona, la empresa y la
 * relación se siembran en base: Contratos no usa los modelos de Core HR ni de Organización.
 *
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => self::employment(),
            'contract_type_id' => fn (): string => ContractType::factory()->create(['category' => 'indefinite'])->id,
            'status' => 'active',
            'work_modality' => 'onsite',
            'valid_from' => '2025-01-01',
        ];
    }

    /** Relación laboral activa (desde 2025-01-01) de una persona nueva en una empresa nueva de la organización actual. */
    public static function employment(string $hireDate = '2025-01-01'): string
    {
        $tenant = TenancyScope::requireTenant();
        $legalEntity = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $legalEntity, 'tenant_id' => $tenant, 'legal_name' => 'Empresa '.Str::random(6).' S.A.C.',
            'country_code' => 'PE', 'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN',
            'timezone' => 'America/Lima', 'status' => 'active',
        ]);
        $person = (string) Str::uuid();
        DB::table('people')->insert([
            'id' => $person, 'tenant_id' => $tenant, 'first_names' => 'Persona', 'paternal_surname' => 'Contrato '.Str::random(5),
        ]);
        $employment = (string) Str::uuid();
        DB::table('employments')->insert([
            'id' => $employment, 'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => $legalEntity,
            'employee_code' => 'C'.Str::upper(Str::random(7)), 'hire_date' => $hireDate, 'status' => 'active',
        ]);

        return $employment;
    }
}
