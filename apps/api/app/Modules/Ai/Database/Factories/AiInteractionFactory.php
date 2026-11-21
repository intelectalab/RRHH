<?php

namespace App\Modules\Ai\Database\Factories;

use App\Modules\Ai\Infrastructure\Models\AiInteraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiInteraction>
 */
class AiInteractionFactory extends Factory
{
    protected $model = AiInteraction::class;

    public function definition(): array
    {
        return ['use_case' => 'ayuda', 'actor_kind' => 'user', 'prompt_enc' => 'x', 'data_scopes' => '{help_articles}', 'provider' => 'fake_ai', 'input_tokens' => 1, 'output_tokens' => 1];
    }
}
