<?php

namespace App\Shared\Outbox\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $consumer
 * @property string $event_id
 * @property CarbonInterface $processed_at
 * @property CarbonInterface $created_at
 */
class ProcessedEvent extends Model
{
    use HasUuids;

    protected $table = 'processed_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'consumer',
        'event_id',
        'processed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
