<?php

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Infrastructure\Models\SupportAccessGrant;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportAccessGrant>
 */
class SupportAccessGrantFactory extends Factory
{
    protected $model = SupportAccessGrant::class;

    public function definition(): array
    {
        return [
            'granted_by_user_id' => User::factory(),
            'reason' => 'Revisión de una incidencia reportada',
            'access_level' => 'read_only',
            'starts_at' => now(),
            'expires_at' => now()->addDays(2),
        ];
    }
}
