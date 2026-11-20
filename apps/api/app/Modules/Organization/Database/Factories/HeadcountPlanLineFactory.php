<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\HeadcountPlanLine;
use App\Modules\Organization\Infrastructure\Models\HeadcountScenario;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plaza nueva dentro de un escenario en borrador.
 *
 * @extends Factory<HeadcountPlanLine>
 */
class HeadcountPlanLineFactory extends Factory
{
    protected $model = HeadcountPlanLine::class;

    public function definition(): array
    {
        return [
            'headcount_scenario_id' => fn (): string => HeadcountScenario::factory()->create()->id,
            'org_unit_id' => fn (): string => OrgUnit::factory()->create()->id,
            'job_profile_id' => fn (): string => JobProfile::factory()->create()->id,
            'planned_count' => 1,
            'change' => 'add',
        ];
    }
}
