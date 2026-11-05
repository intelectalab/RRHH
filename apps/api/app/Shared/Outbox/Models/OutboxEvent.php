<?php

namespace App\Shared\Outbox\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<string, mixed> $payload
 * @property CarbonInterface $occurred_at
 * @property string $status
 * @property int $attempts
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $published_at
 * @property string|null $last_error
 * @property string|null $correlation_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD = 'dead';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'occurred_at',
        'status',
        'attempts',
        'next_attempt_at',
        'published_at',
        'last_error',
        'correlation_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'published_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
