<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Modules\Documents\Database\Factories\DocumentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla documents · DOC-1 · DOC-2 (ME-12). Documento del legajo de una relación laboral (o de una empresa); el
 * archivo está en sus versiones. subject_type indica el módulo que lo generó (contract…): esos se gestionan allí.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_category_id
 * @property string $title
 * @property string|null $owner_person_id
 * @property string|null $employment_id
 * @property string|null $legal_entity_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $current_version_id
 * @property string $classification
 * @property string $status
 * @property string|null $issued_on
 * @property string|null $expires_on
 * @property string $source
 * @property bool $visible_to_employee
 * @property string|null $responsible_user_id
 * @property string|null $description
 * @property string|null $expiry_alerted_on
 * @property Carbon|null $archived_at
 * @property string|null $archive_reason
 * @property int $lock_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class Document extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'documents';

    protected $guarded = ['id', 'tenant_id', 'lock_version', 'status'];

    protected $attributes = ['status' => 'active', 'source' => 'upload', 'classification' => 'internal', 'visible_to_employee' => false];

    protected $casts = ['visible_to_employee' => 'boolean', 'lock_version' => 'integer', 'archived_at' => 'datetime'];

    public function day(string $column): ?string
    {
        $v = $this->getAttribute($column);

        return $v === null ? null : substr((string) $v, 0, 10);
    }

    /** Módulo que gestiona el documento (contract → contracts); null si se subió al legajo. */
    public function managedBy(): ?string
    {
        return match ($this->subject_type) {
            null, 'document' => null,
            'contract', 'contract_addendum' => 'contracts',
            default => (string) $this->subject_type,
        };
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
