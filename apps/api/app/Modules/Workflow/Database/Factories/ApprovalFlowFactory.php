<?php

namespace App\Modules\Workflow\Database\Factories;

use App\Modules\Workflow\Infrastructure\Models\ApprovalFlow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApprovalFlow>
 */
class ApprovalFlowFactory extends Factory
{
    protected $model = ApprovalFlow::class;

    public function definition(): array
    {
        return [
            'code' => 'flow_'.Str::lower(Str::random(8)),
            'name' => 'Flujo '.$this->faker->word(),
            'kind' => 'approval',
            'subject_type' => 'vacation_request',
            'status' => 'draft',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ApprovalFlow $flow): void {
            $flow->versions()->create(['version' => 1, 'graph' => ['levels' => [], 'conditions' => []]]);
        });
    }
}
