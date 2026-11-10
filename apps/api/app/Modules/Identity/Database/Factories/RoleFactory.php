<?php

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'code' => 'custom_'.Str::lower(Str::random(10)),
            'name' => 'Rol '.$this->faker->unique()->word(),
            'is_system' => false,
        ];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function withPermissions(array $permissions, string $scope = 'organization'): static
    {
        return $this->afterCreating(function (Role $role) use ($permissions, $scope): void {
            foreach ($permissions as $code) {
                $role->grants()->create(['permission_code' => $code, 'scope' => $scope]);
            }
        });
    }
}
