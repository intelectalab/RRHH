<?php

namespace App\Modules\Audit\Database\Factories;

use App\Modules\Audit\Infrastructure\Models\AuditIntegrityCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditIntegrityCheck>
 */
class AuditIntegrityCheckFactory extends Factory
{
    protected $model = AuditIntegrityCheck::class;

    public function definition(): array
    {
        return [
            'status' => 'ok',
            'checked_rows' => 0,
            'issues' => '[]',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
