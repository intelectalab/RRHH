<?php

namespace App\Modules\Contracts\Database\Factories;

use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractAddendum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Adenda en borrador sobre un contrato vigente.
 *
 * @extends Factory<ContractAddendum>
 */
class ContractAddendumFactory extends Factory
{
    protected $model = ContractAddendum::class;

    public function definition(): array
    {
        return [
            'contract_id' => fn (): string => Contract::factory()->create()->id,
            'kind' => 'other',
            'effective_on' => '2026-01-01',
            'description' => 'Cambio pactado',
            'status' => 'draft',
        ];
    }
}
