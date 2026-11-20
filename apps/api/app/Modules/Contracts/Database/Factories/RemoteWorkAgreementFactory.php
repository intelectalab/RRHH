<?php

namespace App\Modules\Contracts\Database\Factories;

use App\Modules\Contracts\Infrastructure\Models\RemoteWorkAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solicitud de teletrabajo parcial de una relación laboral nueva de la organización actual.
 *
 * @extends Factory<RemoteWorkAgreement>
 */
class RemoteWorkAgreementFactory extends Factory
{
    protected $model = RemoteWorkAgreement::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => ContractFactory::employment(),
            'modality' => 'partial',
            'starts_on' => '2026-02-01',
            'requested_on' => '2026-01-15',
            'response_due_on' => '2026-01-29',
            'requested_by' => 'employee',
            'status' => 'requested',
        ];
    }
}
