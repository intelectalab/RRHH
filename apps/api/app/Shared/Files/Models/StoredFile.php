<?php

namespace App\Shared\Files\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $storage_key
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $classification
 * @property string|null $uploaded_by_user_id
 * @property string $scan_status
 * @property CarbonInterface|null $retention_until
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class StoredFile extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'stored_files';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CLASSIFICATION_PUBLIC = 'public';

    public const CLASSIFICATION_INTERNAL = 'internal';

    public const CLASSIFICATION_CONFIDENTIAL = 'confidential';

    public const CLASSIFICATION_RESTRICTED = 'restricted';

    public const SCAN_PENDING = 'pending';

    public const SCAN_CLEAN = 'clean';

    public const SCAN_INFECTED = 'infected';

    public const SCAN_SKIPPED = 'skipped';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'storage_key',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'classification',
        'uploaded_by_user_id',
        'scan_status',
        'retention_until',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'size_bytes' => 'integer',
        'retention_until' => 'date',
    ];
}
