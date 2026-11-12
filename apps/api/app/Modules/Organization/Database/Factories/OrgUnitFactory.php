<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<OrgUnit>
 */
class OrgUnitFactory extends Factory
{
    protected $model = OrgUnit::class;

    public function definition(): array
    {
        return [
            'code' => 'U'.$this->faker->unique()->numerify('#####'),
            'name' => 'Unidad '.$this->faker->unique()->word(),
            'valid_from' => '2024-01-01',
            'depth' => 0,
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (OrgUnit $unit): void {
            // Toda unidad tiene su primera versión de estructura (como la crea el servicio).
            DB::table('org_unit_versions')->insert([
                'tenant_id' => $unit->tenant_id, 'org_unit_id' => $unit->id, 'parent_id' => $unit->parent_id,
                'name' => $unit->name, 'org_unit_type_id' => $unit->org_unit_type_id, 'valid_from' => '2024-01-01',
            ]);
            if ($unit->path === null) {
                DB::table('org_units')->where('id', $unit->id)->update(['path' => '/'.$unit->id]);
            }
        });
    }
}
