<?php

namespace App\Modules\Vacations\Database\Factories;

use App\Modules\Vacations\Infrastructure\Models\VacationAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Acuerdo de fraccionamiento en borrador de una relación laboral nueva.
 *
 * @extends Factory<VacationAgreement>
 */
class VacationAgreementFactory extends Factory
{
    protected $model = VacationAgreement::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => VacationRequestFactory::employment(),
            'kind' => 'fractionation',
            'effective_on' => '2026-01-01',
            'status' => 'draft',
        ];
    }
}
