<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Shared\Security\BlindIndex;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persona con un DNI ficticio único (cifrado y con huella, como lo guarda el sistema).
 *
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'first_names' => $this->faker->firstName(),
            'paternal_surname' => $this->faker->lastName(),
            'maternal_surname' => $this->faker->lastName(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Person $person): void {
            $number = (string) $this->faker->unique()->numerify('9#######');
            DB::table('person_documents')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => TenancyScope::requireTenant(), 'person_id' => $person->id,
                'doc_type' => 'dni', 'issuing_country' => 'PE', 'is_primary' => true,
                'number_enc' => FieldCipher::encrypt($number), 'number_hash' => BlindIndex::for('national_id', 'dni:'.$number),
            ]);
        });
    }
}
