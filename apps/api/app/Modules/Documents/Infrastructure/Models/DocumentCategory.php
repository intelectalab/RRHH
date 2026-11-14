<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Modules\Documents\Database\Factories\DocumentCategoryFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla document_categories · DOC-1. Las carpetas del legajo no tienen parent_id; los tipos documentales cuelgan de
 * una carpeta.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $parent_id
 * @property string $default_classification
 * @property string|null $retention_policy_id
 * @property string|null $required_permission_code
 * @property string $scope
 * @property string|null $description
 * @property bool $requires_expiry
 * @property int|null $expiry_notice_days
 * @property bool $visible_to_employee
 * @property bool $requires_review
 * @property bool $is_system
 * @property int $sort_order
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class DocumentCategory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DocumentCategoryFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'document_categories';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => 'active'];

    protected $casts = [
        'requires_expiry' => 'boolean', 'visible_to_employee' => 'boolean', 'requires_review' => 'boolean', 'is_system' => 'boolean',
        'expiry_notice_days' => 'integer', 'sort_order' => 'integer',
    ];

    protected static function newFactory(): DocumentCategoryFactory
    {
        return DocumentCategoryFactory::new();
    }
}
