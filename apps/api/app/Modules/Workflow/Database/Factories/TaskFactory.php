<?php

namespace App\Modules\Workflow\Database\Factories;

use App\Modules\Workflow\Infrastructure\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => 'Tarea '.$this->faker->word(),
            'priority' => 'normal',
            'status' => 'pending',
            'due_on' => now()->addDays(3)->toDateString(),
        ];
    }
}
