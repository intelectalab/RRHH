<?php

namespace App\Modules\Ai\Database\Factories;

use App\Modules\Ai\Infrastructure\Models\AiUseCaseSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiUseCaseSetting>
 */
class AiUseCaseSettingFactory extends Factory
{
    protected $model = AiUseCaseSetting::class;

    public function definition(): array
    {
        return ['use_case' => 'caso_'.Str::lower(Str::random(8)), 'is_enabled' => true, 'limit_period' => 'day'];
    }
}
