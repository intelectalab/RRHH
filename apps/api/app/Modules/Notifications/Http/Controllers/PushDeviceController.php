<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\Push\PushDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * NOT-4 · Cada usuario acepta o rechaza push registrando o quitando sus dispositivos.
 */
final class PushDeviceController extends Controller
{
    public function __construct(private readonly PushDevices $devices) {}

    public function config(): JsonResponse
    {
        $key = $this->devices->publicKey();

        return response()->json(['data' => ['available' => $key !== null, 'public_key' => $key]]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->devices->mine($this->userId($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription' => ['required', 'array'],
            'subscription.endpoint' => ['required', 'string', 'url:https', 'max:1000'],
            'subscription.keys' => ['required', 'array'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:200'],
            'subscription.keys.auth' => ['required', 'string', 'max:100'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}} $subscription */
        $subscription = ['endpoint' => (string) $data['subscription']['endpoint'], 'keys' => [
            'p256dh' => (string) $data['subscription']['keys']['p256dh'], 'auth' => (string) $data['subscription']['keys']['auth'],
        ]];
        $id = $this->devices->register($this->userId($request), $subscription, isset($data['label']) ? (string) $data['label'] : null);
        $device = collect($this->devices->mine($this->userId($request)))->firstWhere('id', $id);

        return response()->json(['data' => $device], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $this->devices->revoke($this->userId($request), $id);

        return response()->json(null, 204);
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
