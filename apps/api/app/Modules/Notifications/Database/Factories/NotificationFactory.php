<?php

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'recipient_user_id' => function (): string {
                // Sin importar el modelo de Identity (límite entre módulos): un usuario mínimo de la organización.
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'display_name' => $this->faker->name(), 'status' => 'active']);

                return $id;
            },
            'category' => 'task.assigned',
            'title' => 'Aviso '.$this->faker->word(),
            'body' => 'Detalle del aviso',
            'action_url' => '/tareas',
            'priority' => 'normal',
        ];
    }
}
