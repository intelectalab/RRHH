<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Dependent;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Hijo derechohabiente de la persona de una relación laboral nueva.
 *
 * @extends Factory<Dependent>
 */
class DependentFactory extends Factory
{
    protected $model = Dependent::class;

    public function definition(): array
    {
        return [
            'person_id' => fn (): string => Employment::factory()->create()->person_id,
            'full_name' => $this->faker->firstName().' '.$this->faker->lastName(),
            'relationship' => 'child',
            'birth_date' => '2015-03-10',
            'registry_status' => 'pending',
        ];
    }
}
