<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\BankAccount;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Security\FieldCipher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cuenta de haberes activa de una relación laboral nueva.
 *
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'person_id' => fn (array $a): string => (string) Employment::query()->whereKey($a['employment_id'])->value('person_id'),
            'purpose' => 'salary',
            'bank_code' => 'bcp',
            'account_type' => 'savings',
            'account_number_enc' => fn (): string => FieldCipher::encrypt((string) $this->faker->numerify('19########0##')),
            'currency' => 'PEN',
            'status' => 'active',
            'valid_from' => '2025-01-01',
        ];
    }
}
