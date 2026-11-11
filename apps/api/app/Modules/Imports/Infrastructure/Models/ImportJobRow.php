<?php

namespace App\Modules\Imports\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla import_job_rows · IMP-1 · Una fila del archivo con su validación y el registro creado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $import_job_id
 * @property int $row_number
 * @property array<int, string> $raw_data
 * @property array<string, mixed>|null $parsed_data
 * @property string $status
 * @property array<int, array{column: string|null, code: string, message: string}>|null $errors
 * @property array<int, array{column: string|null, code: string, message: string}>|null $warnings
 * @property string|null $entity_id
 * @property array<string, mixed>|null $previous_data
 * @property int|null $batch_number
 */
class ImportJobRow extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'import_job_rows';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'raw_data' => 'array', 'parsed_data' => 'array', 'errors' => 'array', 'warnings' => 'array',
        'previous_data' => 'array', 'row_number' => 'integer', 'batch_number' => 'integer',
    ];
}
