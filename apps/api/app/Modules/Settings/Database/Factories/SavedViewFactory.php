<?php

namespace App\Modules\Settings\Database\Factories;

use App\Modules\Settings\Infrastructure\Models\SavedView;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<SavedView>
 */
class SavedViewFactory extends Factory
{
    protected $model = SavedView::class;

    public function definition(): array
    {
        return [
            'owner_user_id' => function (): string {
                // Sin importar el modelo de Identity (límite entre módulos): un usuario mínimo de la organización.
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'display_name' => $this->faker->name(), 'status' => 'active']);

                return $id;
            },
            'resource' => 'users',
            'name' => $this->faker->words(2, true),
            'config' => ['filters' => ['status' => 'active'], 'columns' => ['user', 'status'], 'sort' => 'display_name'],
            'is_shared' => true,
        ];
    }
}
