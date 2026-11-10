<?php

namespace App\Modules\Audit\Database\Factories;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'actor_kind' => 'system',
            'action' => 'test.created',
            'entity_type' => 'test',
            'entity_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ];
    }
}
