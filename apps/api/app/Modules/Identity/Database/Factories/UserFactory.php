<?php

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'display_name' => $this->faker->name(),
            'status' => User::STATUS_ACTIVE,
            'locale' => 'es',
            'password_hash' => Hash::make('Clave-de-prueba-123'),
            'password_changed_at' => now(),
        ];
    }

    public function withEmail(string $email): static
    {
        return $this->afterCreating(function (User $user) use ($email): void {
            $user->identities()->create(UserIdentity::attributesFor('email', $email) + ['is_primary' => true]);
        });
    }

    public function withPassword(string $password): static
    {
        return $this->state(fn () => ['password_hash' => Hash::make($password)]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
