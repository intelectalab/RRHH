<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Modules\CoreHR\Infrastructure\Models\ProfileChangeRequest;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solicitud de cambio pendiente de una persona nueva, pedida por un usuario de la misma organización.
 *
 * @extends Factory<ProfileChangeRequest>
 */
class ProfileChangeRequestFactory extends Factory
{
    protected $model = ProfileChangeRequest::class;

    public function definition(): array
    {
        return [
            'person_id' => fn (): string => Person::factory()->create()->id,
            'requested_by_user_id' => function (): string {
                // Sin importar el modelo de Identity (límite entre módulos): un usuario mínimo de la organización.
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'display_name' => $this->faker->name(), 'status' => 'active']);

                return $id;
            },
            'changes' => ['enc' => FieldCipher::encrypt((string) json_encode(['marital_status' => 'married'])), 'applied' => [], 'pending' => ['marital_status']],
            'status' => 'pending',
        ];
    }
}
