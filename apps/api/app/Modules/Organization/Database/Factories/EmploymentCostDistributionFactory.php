<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\CostCenter;
use App\Modules\Organization\Infrastructure\Models\EmploymentCostDistribution;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Distribución 100 % a un centro de costo de una relación laboral nueva (persona y relación sembradas
 * directamente en base: el módulo de colaboradores todavía no expone modelos).
 *
 * @extends Factory<EmploymentCostDistribution>
 */
class EmploymentCostDistributionFactory extends Factory
{
    protected $model = EmploymentCostDistribution::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => self::employment(),
            'valid_from' => '2026-01-01',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (EmploymentCostDistribution $d): void {
            $center = CostCenter::factory()->create();
            $d->lines()->create(['cost_center_id' => $center->id, 'percentage' => '100.00']);
        });
    }

    /** Persona + relación laboral activa en una empresa nueva de la organización actual. */
    public static function employment(?string $legalEntityId = null): string
    {
        $tenant = TenancyScope::requireTenant();
        $legalEntityId ??= LegalEntity::factory()->create()->id;
        $person = (string) Str::uuid();
        $employment = (string) Str::uuid();
        DB::table('people')->insert(['id' => $person, 'tenant_id' => $tenant, 'first_names' => 'Prueba', 'paternal_surname' => 'Centro']);
        DB::table('employments')->insert([
            'id' => $employment, 'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => $legalEntityId,
            'employee_code' => 'E'.Str::random(8), 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);

        return $employment;
    }
}
