<?php

namespace App\Modules\Workflow\Database\Factories;

use App\Modules\Workflow\Infrastructure\Models\VisualFlow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VisualFlow>
 */
class VisualFlowFactory extends Factory
{
    protected $model = VisualFlow::class;

    public function definition(): array
    {
        return [
            'code' => 'vflow_'.Str::lower(Str::random(8)),
            'name' => 'Flujo visual '.$this->faker->word(),
            'kind' => 'automation',
            'status' => 'draft',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (VisualFlow $flow): void {
            $flow->versions()->create(['version' => 1, 'graph' => ['nodes' => [], 'edges' => []]]);
        });
    }
}
