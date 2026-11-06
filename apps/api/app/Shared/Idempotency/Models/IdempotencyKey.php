<?php

namespace App\Shared\Idempotency\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string|null $user_id
 * @property string|null $api_token_id
 * @property string $request_method
 * @property string $request_path
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property CarbonInterface|null $locked_until
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class IdempotencyKey extends Model
{
    use HasUuids;

    protected $table = 'idempotency_keys';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'key',
        'user_id',
        'api_token_id',
        'request_method',
        'request_path',
        'request_hash',
        'response_status',
        'response_body',
        'locked_until',
        'expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'response_status' => 'integer',
        'response_body' => 'array',
        'locked_until' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
