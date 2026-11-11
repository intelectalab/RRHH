<?php

namespace App\Modules\Absences\Database\Factories;

use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Modules\Absences\Infrastructure\Models\AbsenceTypeRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AbsenceType>
 */
class AbsenceTypeFactory extends Factory
{
    protected $model = AbsenceType::class;

    public function definition(): array
    {
        return [
            'code' => 'propio_'.Str::lower(Str::random(8)),
            'name' => 'Tipo '.$this->faker->word(),
            'category' => 'other',
            'country_code' => 'PE',
            'is_system' => false,
            'is_confidential' => false,
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AbsenceType $type): void {
            AbsenceTypeRule::query()->create(['absence_type_id' => $type->id, 'valid_from' => now()->toDateString()]);
        });
    }
}
