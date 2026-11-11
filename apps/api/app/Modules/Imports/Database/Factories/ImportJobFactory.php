<?php

namespace App\Modules\Imports\Database\Factories;

use App\Modules\Imports\Infrastructure\Models\ImportJob;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    public function definition(): array
    {
        return [
            'entity' => 'legal_entities',
            'created_by_user_id' => function (): string {
                // Sin importar el modelo de Identity (límite entre módulos): un usuario mínimo de la organización.
                $id = (string) Str::uuid();
                DB::table('users')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'display_name' => $this->faker->name(), 'status' => 'active']);

                return $id;
            },
            'stored_file_id' => function (): string {
                $id = (string) Str::uuid();
                DB::table('stored_files')->insert([
                    'id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'storage_key' => 'tenants/test/'.$id.'.csv',
                    'original_name' => 'empresas.csv', 'mime_type' => 'text/csv', 'size_bytes' => 10, 'sha256' => hash('sha256', $id),
                ]);

                return $id;
            },
            'file_name' => 'empresas.csv',
            'headers' => ['razon_social', 'ruc'],
            'status' => 'uploaded',
            'total_rows' => 0,
            'dry_run' => false,
        ];
    }
}
