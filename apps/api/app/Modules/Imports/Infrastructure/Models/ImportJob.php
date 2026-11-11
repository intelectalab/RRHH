<?php

namespace App\Modules\Imports\Infrastructure\Models;

use App\Modules\Imports\Database\Factories\ImportJobFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla import_jobs · IMP-1 · Una importación por etapas (ME-41).
 *
 * summary: {"format", "delimiter", "warnings": [...], "duplicate_of": id|null, "ignored_columns": [...],
 *           "blocked": [{"row_number", "reason"}], "failed_row": n|null}
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity
 * @property string|null $import_template_id
 * @property string $stored_file_id
 * @property string $status
 * @property array<string, string>|null $column_mapping
 * @property int|null $total_rows
 * @property int|null $valid_rows
 * @property int|null $error_rows
 * @property int|null $imported_rows
 * @property string $created_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $file_name
 * @property array<int, string>|null $headers
 * @property int|null $batch_size
 * @property array<string, mixed>|null $summary
 * @property string|null $error_message
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImportJob extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ImportJobFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'import_jobs';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'column_mapping' => 'array', 'headers' => 'array', 'summary' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'dry_run' => 'boolean',
        'total_rows' => 'integer', 'valid_rows' => 'integer', 'error_rows' => 'integer', 'imported_rows' => 'integer',
        'batch_size' => 'integer', 'lock_version' => 'integer',
    ];

    protected static function newFactory(): ImportJobFactory
    {
        return ImportJobFactory::new();
    }
}
