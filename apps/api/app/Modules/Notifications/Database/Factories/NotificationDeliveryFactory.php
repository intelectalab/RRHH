<?php

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    public function definition(): array
    {
        return [
            'channel' => 'email',
            'recipient_address_enc' => Crypt::encryptString($this->faker->safeEmail()),
            'status' => 'sent',
            'provider' => 'fake_email',
            'attempts' => 1,
            'sent_at' => now(),
        ];
    }
}
