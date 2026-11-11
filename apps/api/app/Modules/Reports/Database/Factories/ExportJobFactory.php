<?php

namespace App\Modules\Reports\Database\Factories;

use App\Modules\Reports\Infrastructure\Models\ExportJob;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExportJob>
 */
class ExportJobFactory extends Factory
{
    protected $model = ExportJob::class;

    public function definition(): array
    {
        return [
            'requested_by_user_id' => function (): string {
                // Sin importar el modelo de Identity (límite entre módulos): un usuario mínimo de la organización.
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'display_name' => $this->faker->name(), 'status' => 'active']);

                return $id;
            },
            'resource' => 'legal_entities',
            'format' => 'csv',
            'filters' => [],
            'columns' => ['name'],
            'status' => 'completed',
            'row_count' => 0,
            'expires_at' => now()->addDays(7),
        ];
    }
}
