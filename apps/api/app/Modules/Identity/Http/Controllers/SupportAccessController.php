<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Application\Support\SupportAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SAS-8 · Acceso del soporte de GestiaHR, administrado por la organización, e ingreso con el enlace de soporte.
 */
final class SupportAccessController
{
    public function __construct(private readonly SupportAccess $support) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->support->overview()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $this->support->setEnabled((bool) $data['enabled'], (string) $data['reason']);

        return response()->json(['data' => $this->support->overview()]);
    }

    public function grant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'access_level' => ['required', Rule::in(['read_only', 'full'])],
            'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:'.now()->addDays(30)->toIso8601String()],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->support->grant([
            'reason' => (string) $data['reason'], 'access_level' => (string) $data['access_level'], 'expires_at' => (string) $data['expires_at'],
        ], $user)], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->support->revoke($id, $user, isset($data['reason']) ? (string) $data['reason'] : null)]);
    }

    public function enter(Request $request, SessionManager $sessions): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:200']]);
        ['session' => $session, 'token' => $token] = $this->support->enter((string) $data['token'], $request);

        $response = response()->json(['data' => ['expires_at' => $session->expires_at->toIso8601String()]]);
        foreach ($sessions->cookies($token, $session) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
