<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Mfa\MfaService;
use App\Modules\Identity\Application\QuickUnlock;
use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ESS-6 · Reingreso rápido con PIN en el teléfono (ver QuickUnlock).
 */
final class QuickUnlockController
{
    public function __construct(
        private readonly QuickUnlock $unlock,
        private readonly SessionManager $sessions,
        private readonly MfaService $mfa,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->unlock->status($this->deviceToken($request)) + ['days' => $this->unlock->days()]]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'confirmed', 'max:8']]);
        /** @var User $user */
        $user = $request->user();
        $result = $this->unlock->enroll($user, $this->sessions->resolve($request), (string) $data['pin'], $request, $this->deviceToken($request));

        return response()->json(['data' => ['enabled' => true, 'expires_at' => $result['expires_at']]], 201)
            ->withCookie($this->unlock->cookie($result['token'], $this->unlock->days()));
    }

    public function signIn(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'max:8']]);
        $result = $this->unlock->signIn($this->deviceToken($request), (string) $data['pin'], $request);
        if ($result === null) {
            return ProblemDetailsResponse::make(422, 'invalid_credentials', null, null, [
                ['field' => 'pin', 'rule' => 'invalid', 'message' => (string) trans('identity.quick_unlock.wrong_pin')],
            ]);
        }
        ['user' => $user, 'session' => $session, 'token' => $token] = $result;
        $response = response()->json(['data' => [
            'user' => ['id' => $user->id, 'display_name' => $user->display_name, 'locale' => $user->locale, 'must_change_password' => $user->must_change_password],
            'session' => ['id' => $session->id, 'expires_at' => $session->expires_at->toIso8601String()],
            'mfa_step' => $this->mfa->sessionStatus($user, $session),
        ]]);
        foreach ($this->sessions->cookies($token, $session) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public function forget(Request $request): JsonResponse
    {
        $this->unlock->forget($this->deviceToken($request));

        return response()->json(null, 204)->withCookie($this->unlock->forgetCookie());
    }

    private function deviceToken(Request $request): ?string
    {
        $token = $request->cookie(QuickUnlock::cookieName());

        return is_string($token) && $token !== '' ? $token : null;
    }
}
