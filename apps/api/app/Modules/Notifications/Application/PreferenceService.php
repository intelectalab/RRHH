<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Application\Push\PushDevices;
use App\Modules\Notifications\Infrastructure\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NOT-6 · Preferencias por usuario, tipo de aviso y canal.
 */
final class PreferenceService
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly PushDevices $push,
    ) {}

    /**
     * @return array<int, array{category: string, mandatory: bool, urgent: bool, channels: array<string, array{enabled: bool, locked: bool}>}>
     */
    public function effective(string $userId): array
    {
        $saved = NotificationPreference::query()->where('user_id', $userId)->get()->keyBy(fn ($p) => $p->category.'|'.$p->channel);
        $out = [];
        // NOT-4: push vale para todos los avisos de quien registró un dispositivo, salvo los que apague.
        $hasPush = in_array('push', $this->catalog->channels(), true) && $this->push->hasActive($userId);
        foreach ($this->catalog->categories() as $code => $cat) {
            $mandatory = $this->catalog->isMandatory($code);
            $channels = [];
            foreach ($this->catalog->channels() as $channel) {
                $locked = $mandatory && $channel === 'in_app';
                $row = $saved->get($code.'|'.$channel);
                $default = $channel === 'push' ? $hasPush : in_array($channel, $cat['channels'], true);
                $enabled = $locked || ($row instanceof NotificationPreference ? $row->enabled : $default);
                // Sin dispositivo no hay a dónde enviar push, aunque el tipo quede marcado.
                $channels[$channel] = ['enabled' => $channel === 'push' ? $enabled && $hasPush : $enabled, 'locked' => $locked];
            }
            $out[] = ['category' => $code, 'mandatory' => $mandatory, 'urgent' => (bool) $cat['urgent'], 'channels' => $channels];
        }

        return $out;
    }

    public function enabled(string $userId, string $category, string $channel): bool
    {
        foreach ($this->effective($userId) as $row) {
            if ($row['category'] === $category) {
                return $row['channels'][$channel]['enabled'] ?? false;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{category: string, channel: string, enabled: bool}>  $changes
     */
    public function save(string $userId, array $changes): void
    {
        $errors = [];
        foreach ($changes as $i => $c) {
            if ($this->catalog->category($c['category']) === null) {
                $errors["preferences.{$i}.category"][] = (string) trans('notifications.unknown_category');
            } elseif (! in_array($c['channel'], $this->catalog->channels(), true)) {
                $errors["preferences.{$i}.channel"][] = (string) trans('notifications.unsupported_channel');
            } elseif (! $c['enabled'] && $c['channel'] === 'in_app' && $this->catalog->isMandatory($c['category'])) {
                $errors["preferences.{$i}.enabled"][] = (string) trans('notifications.mandatory_in_app'); // RN-NOT-05
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($userId, $changes): void {
            foreach ($changes as $c) {
                NotificationPreference::query()->updateOrCreate(['user_id' => $userId, 'category' => $c['category'], 'channel' => $c['channel']], ['enabled' => $c['enabled']]);
            }
        });
    }
}
