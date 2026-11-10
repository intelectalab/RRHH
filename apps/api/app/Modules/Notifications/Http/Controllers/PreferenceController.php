<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\NotificationCatalog;
use App\Modules\Notifications\Application\PreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOT-6 · Preferencias del usuario actual: tipo de aviso × canal.
 */
final class PreferenceController
{
    public function __construct(
        private readonly PreferenceService $preferences,
        private readonly NotificationCatalog $catalog,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond((string) $request->user()?->getAuthIdentifier());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'max:100'],
            'preferences.*.category' => ['required', 'string', 'max:60'],
            'preferences.*.channel' => ['required', 'string', 'max:20'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);
        $userId = (string) $request->user()?->getAuthIdentifier();
        /** @var array<int, array{category: string, channel: string, enabled: bool}> $changes */
        $changes = array_map(fn ($p) => ['category' => (string) $p['category'], 'channel' => (string) $p['channel'], 'enabled' => (bool) $p['enabled']], $data['preferences']);
        $this->preferences->save($userId, $changes);

        return $this->respond($userId);
    }

    private function respond(string $userId): JsonResponse
    {
        $until = $this->catalog->quietUntil(now());

        return response()->json(['data' => [
            'channels' => $this->catalog->channels(),
            'categories' => $this->preferences->effective($userId),
            'quiet_hours' => [
                'from' => $this->catalog->setting('notificaciones.silencio_desde'),
                'to' => $this->catalog->setting('notificaciones.silencio_hasta'),
                'active_until' => $until?->toIso8601String(),
            ],
        ]]);
    }
}
