<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Modules\Documents\Database\Factories\DocumentGenerationBatchFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla document_generation_batches · DOC-5. Lote de generación con su avance; items guarda por relación laboral
 * el documento generado o el motivo por el que se omitió.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_template_id
 * @property string $document_template_version_id
 * @property string|null $requested_by_user_id
 * @property string $status
 * @property int $total
 * @property int $generated
 * @property int $failed
 * @property list<array<string, mixed>>|null $items
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int $lock_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DocumentGenerationBatch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DocumentGenerationBatchFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'document_generation_batches';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'queued', 'total' => 0, 'generated' => 0, 'failed' => 0];

    protected $casts = ['items' => 'array', 'total' => 'integer', 'generated' => 'integer', 'failed' => 'integer', 'lock_version' => 'integer',
        'started_at' => 'datetime', 'finished_at' => 'datetime'];

    protected static function newFactory(): DocumentGenerationBatchFactory
    {
        return DocumentGenerationBatchFactory::new();
    }
}
