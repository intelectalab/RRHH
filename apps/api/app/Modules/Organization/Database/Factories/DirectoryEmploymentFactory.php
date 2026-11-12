<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\DirectoryEmployment;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persona + relación laboral activa con su asignación vigente (puesto, unidad y sede), sembradas
 * directamente en base: el módulo de colaboradores todavía no expone modelos.
 *
 * @extends Factory<DirectoryEmployment>
 */
class DirectoryEmploymentFactory extends Factory
{
    protected $model = DirectoryEmployment::class;

    public function definition(): array
    {
        return [
            'person_id' => function (): string {
                $id = (string) Str::uuid();
                DB::table('people')->insert([
                    'id' => $id, 'tenant_id' => TenancyScope::requireTenant(),
                    'first_names' => 'Persona', 'paternal_surname' => 'Directorio '.Str::random(5),
                ]);

                return $id;
            },
            'legal_entity_id' => fn (): string => LegalEntity::factory()->create()->id,
            'employee_code' => 'E'.Str::random(8),
            'hire_date' => '2025-01-01',
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DirectoryEmployment $e): void {
            DB::table('employment_assignments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $e->tenant_id, 'employment_id' => $e->id,
                'job_profile_id' => JobProfile::factory()->create()->id,
                'org_unit_id' => OrgUnit::factory()->create()->id,
                'site_id' => Site::factory()->create()->id,
                'valid_from' => '2025-01-01',
            ]);
        });
    }
}
