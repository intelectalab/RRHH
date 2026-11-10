<?php

namespace App\Modules\Audit\Database\Factories;

use App\Modules\Audit\Infrastructure\Models\SensitiveAccessLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SensitiveAccessLog>
 */
class SensitiveAccessLogFactory extends Factory
{
    protected $model = SensitiveAccessLog::class;

    public function definition(): array
    {
        return [
            'access_kind' => 'api',
            'data_category' => 'salary',
            'entity_type' => 'Employment',
            'entity_id' => (string) Str::uuid(),
            'fields' => '{base_salary}',
            'occurred_at' => now(),
        ];
    }
}
