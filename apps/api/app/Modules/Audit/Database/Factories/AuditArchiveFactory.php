<?php

namespace App\Modules\Audit\Database\Factories;

use App\Modules\Audit\Infrastructure\Models\AuditArchive;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditArchive>
 */
class AuditArchiveFactory extends Factory
{
    protected $model = AuditArchive::class;

    public function definition(): array
    {
        // Un mes distinto por manifiesto (uno por organización y mes).
        $month = now()->startOfMonth()->subMonths(24 + $this->faker->unique()->numberBetween(0, 600));

        return [
            'period_month' => $month->toDateString(),
            'row_count' => 0,
            'storage_disk' => 'local',
            'storage_key' => 'audit-archives/test/'.$month->format('Y-m').'.jsonl',
            'sha256' => hash('sha256', ''),
            'archived_at' => now(),
            'purge_after' => $month->copy()->addYears(5)->toDateString(),
        ];
    }
}
