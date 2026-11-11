<?php

namespace App\Modules\Workflow\Database\Factories;

use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Modules\Workflow\Infrastructure\Models\AutomationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRun>
 */
class AutomationRunFactory extends Factory
{
    protected $model = AutomationRun::class;

    public function definition(): array
    {
        return [
            'automation_rule_id' => AutomationRule::factory(),
            'status' => 'skipped',
            'started_at' => now(),
            'finished_at' => now(),
            'log' => ['event_type' => 'employee.hired.v1', 'payload' => [], 'attempts' => 1, 'actions' => []],
        ];
    }
}
