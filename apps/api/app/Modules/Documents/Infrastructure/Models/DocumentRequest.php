<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Modules\Documents\Database\Factories\DocumentRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla document_requests · DOC-3. Solicitud de un tipo documental a un colaborador (una por persona; el envío
 * se agrupa en request_group_id). Los estados cambian solo por acciones (DocumentRequestStateMachine).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $employment_id
 * @property string|null $person_id
 * @property string $document_category_id
 * @property string|null $requested_by_user_id
 * @property string|null $due_on
 * @property string $status
 * @property string|null $document_id
 * @property string|null $rejection_reason
 * @property string|null $instructions
 * @property string|null $request_group_id
 * @property string $title
 * @property Carbon|null $submitted_at
 * @property string|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $cancellation_reason
 * @property int $lock_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DocumentRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DocumentRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'document_requests';

    protected $guarded = ['id', 'tenant_id', 'lock_version', 'status'];

    protected $attributes = ['status' => 'requested'];

    protected $casts = ['lock_version' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function dueOn(): ?string
    {
        return $this->due_on === null ? null : substr((string) $this->due_on, 0, 10);
    }

    protected static function newFactory(): DocumentRequestFactory
    {
        return DocumentRequestFactory::new();
    }
}
