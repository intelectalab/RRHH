<?php

namespace App\Modules\Vacations\Database\Factories;

use App\Modules\Vacations\Infrastructure\Models\VacationPolicy;
use App\Modules\Vacations\Infrastructure\Models\VacationPolicyRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Política sin alcance (aplica a toda la organización) con reglas por defecto vigentes desde 2020: los
 * días por año salen de los parámetros legales del régimen.
 *
 * @extends Factory<VacationPolicy>
 */
class VacationPolicyFactory extends Factory
{
    protected $model = VacationPolicy::class;

    public function definition(): array
    {
        return [
            'code' => 'pol_'.Str::lower(Str::random(8)),
            'name' => 'Política '.$this->faker->word(),
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (VacationPolicy $policy): void {
            VacationPolicyRule::query()->create(['vacation_policy_id' => $policy->id, 'valid_from' => '2020-01-01']);
        });
    }
}
