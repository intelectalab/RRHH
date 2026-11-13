<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\HeadcountBudget;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Presupuesto de dotación vigente de una unidad de la organización actual.
 *
 * @extends Factory<HeadcountBudget>
 */
class HeadcountBudgetFactory extends Factory
{
    protected $model = HeadcountBudget::class;

    public function definition(): array
    {
        return [
            'legal_entity_id' => fn (): string => LegalEntity::factory()->create()->id,
            'org_unit_id' => fn (): string => OrgUnit::factory()->create()->id,
            'budgeted_positions' => 5,
            'valid_from' => '2026-01-01',
        ];
    }
}
