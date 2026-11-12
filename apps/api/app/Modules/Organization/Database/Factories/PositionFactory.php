<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Posición vacante con puesto, empresa y unidad propios de la organización actual.
 *
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'code' => 'P'.$this->faker->unique()->numerify('######'),
            'title' => 'Plaza '.$this->faker->unique()->word(),
            'job_profile_id' => fn (): string => JobProfile::factory()->create()->id,
            'legal_entity_id' => fn (): string => LegalEntity::factory()->create()->id,
            'org_unit_id' => fn (): string => OrgUnit::factory()->create()->id,
            'status' => 'open',
            'valid_from' => '2026-01-01',
        ];
    }
}
