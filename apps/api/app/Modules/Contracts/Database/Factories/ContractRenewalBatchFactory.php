<?php

namespace App\Modules\Contracts\Database\Factories;

use App\Modules\Contracts\Infrastructure\Models\ContractRenewalBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CON-5 · Lote de renovación en Borrador creado por un usuario nuevo de la organización actual.
 *
 * @extends Factory<ContractRenewalBatch>
 */
class ContractRenewalBatchFactory extends Factory
{
    protected $model = ContractRenewalBatch::class;

    public function definition(): array
    {
        return [
            'name' => 'Renovaciones '.Str::random(4),
            'status' => 'draft',
            'new_end_date' => now()->addMonths(6)->toDateString(),
            'created_by_user_id' => function (): string {
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => DB::table('current_tenant')->value('id'), 'display_name' => 'Usuario '.Str::random(5), 'status' => 'active']);

                return $id;
            },
        ];
    }
}
