<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\PositionRequest;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solicitud de posición en borrador de la organización actual.
 *
 * @extends Factory<PositionRequest>
 */
class PositionRequestFactory extends Factory
{
    protected $model = PositionRequest::class;

    public function definition(): array
    {
        return [
            'requested_by_user_id' => function (): string {
                // Sin importar el modelo de Identity (límite entre módulos): un usuario mínimo de la organización.
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'display_name' => $this->faker->name(), 'status' => 'active']);

                return $id;
            },
            'legal_entity_id' => fn (): string => LegalEntity::factory()->create()->id,
            'org_unit_id' => fn (): string => OrgUnit::factory()->create()->id,
            'job_profile_id' => fn (): string => JobProfile::factory()->create()->id,
            'title' => 'Plaza '.$this->faker->unique()->word(),
            'quantity' => 1,
            'justification' => 'Crecimiento del área',
            'kind' => 'new',
            'status' => 'draft',
        ];
    }
}
