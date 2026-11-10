<?php

namespace App\Modules\Notifications\Database\Factories;

use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        // Combinación única (tipo, canal, idioma) dentro de la organización.
        $combos = [];
        foreach (array_keys((array) config('notifications.categories')) as $code) {
            foreach (['in_app', 'email'] as $channel) {
                foreach (['es', 'en', 'pt'] as $locale) {
                    $combos[] = "{$code}|{$channel}|{$locale}";
                }
            }
        }
        [$code, $channel, $locale] = explode('|', (string) $this->faker->unique()->randomElement($combos));

        return [
            'code' => $code,
            'channel' => $channel,
            'locale' => $locale,
            'subject' => 'Plantilla '.$this->faker->word(),
            'body' => 'Hola {name}',
            'variables' => [],
            'is_system' => false,
        ];
    }
}
