<?php

namespace App\Modules\Audit\Database\Factories;

use App\Modules\Audit\Infrastructure\Models\ActivityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ActivityEvent>
 */
class ActivityEventFactory extends Factory
{
    protected $model = ActivityEvent::class;

    public function definition(): array
    {
        return [
            'activity' => 'record.viewed',
            'subject_type' => 'user',
            'subject_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ];
    }
}
