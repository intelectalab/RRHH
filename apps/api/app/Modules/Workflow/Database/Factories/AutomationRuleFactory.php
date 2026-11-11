<?php

namespace App\Modules\Workflow\Database\Factories;

use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Shared\Outbox\DomainEventTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Regla '.$this->faker->word(),
            'trigger_event' => DomainEventTypes::EMPLOYEE_HIRED,
            'conditions' => ['match' => 'all', 'rules' => []],
            'actions' => [['type' => 'notify', 'user_field' => 'employee_id', 'title' => 'Aviso', 'message' => 'Hola']],
            'status' => 'draft',
        ];
    }
}
