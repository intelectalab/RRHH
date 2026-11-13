<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\PersonnelAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Movimiento de personal programado a futuro sobre una relación laboral nueva de la organización actual.
 *
 * @extends Factory<PersonnelAction>
 */
class PersonnelActionFactory extends Factory
{
    protected $model = PersonnelAction::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'kind' => 'transfer',
            'effective_on' => now()->addMonth()->toDateString(),
            'reason' => 'Traslado programado',
            'status' => 'scheduled',
            'changes' => ['work_modality' => 'remote'],
        ];
    }
}
