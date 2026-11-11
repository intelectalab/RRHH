<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Console\ConsoleAuth;
use App\Modules\Platform\Application\Console\ConsoleRoles;
use App\Modules\Platform\Application\Console\ConsoleSessions;
use App\Modules\Platform\Infrastructure\Models\PlatformSession;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SAS-1 · Ingreso propio de la consola: clave → segundo factor (siempre) → consola.
 */
final class ConsoleAuthController
{
    public function __construct(
        private readonly ConsoleAuth $auth,
        private readonly ConsoleSessions $sessions,
        private readonly ConsoleRoles $roles,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = $this->auth->attempt((string) $request->input('email'), (string) $request->input('password'));
        if ($user === null) {
            return ProblemDetailsResponse::make(422, 'invalid_credentials', (string) trans('platform.invalid_credentials'), null, [
                ['field' => 'credentials', 'rule' => 'invalid', 'message' => (string) trans('platform.invalid_credentials')],
            ]);
        }

        ['session' => $session, 'token' => $token] = $this->sessions->open($user, $request);
        $response = response()->json(['data' => $this->payload($user, $session)]);
        foreach ($this->sessions->cookies($token, $session) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public function me(Request $request): JsonResponse
    {
        [$user, $session] = $this->current($request);

        return response()->json(['data' => $this->payload($user, $session)]);
    }

    public function startTotp(Request $request): JsonResponse
    {
        [$user] = $this->current($request);

        return response()->json(['data' => $this->auth->startEnrollment($user)]);
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);
        [$user, $session] = $this->current($request);
        $this->auth->confirmEnrollment($user, $session, (string) $request->input('code'));

        return response()->json(['data' => $this->payload($user->refresh(), $session->refresh())]);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);
        [$user, $session] = $this->current($request);
        $this->auth->verify($user, $session, (string) $request->input('code'));

        return response()->json(['data' => $this->payload($user->refresh(), $session->refresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        [$user, $session] = $this->current($request);
        $this->auth->logout($user, $session);

        $response = response()->json(['data' => ['signed_out' => true]]);
        foreach ($this->sessions->forgetCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * @return array{0: PlatformUser, 1: PlatformSession}
     */
    private function current(Request $request): array
    {
        $user = $request->user('platform');
        $session = $request->attributes->get(PlatformSession::class);
        abort_unless($user instanceof PlatformUser && $session instanceof PlatformSession, 401);

        return [$user, $session];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlatformUser $user, PlatformSession $session): array
    {
        $step = $this->auth->step($user, $session);

        return [
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'role_label' => (string) trans('platform.roles.'.$user->role),
            ],
            'session' => [
                'id' => $session->id,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
            // Siempre se exige: enroll (configurarlo) o verify (pedir el código) hasta que sea ok.
            'mfa_step' => $step,
            // Sin el segundo paso no se informa ningún permiso.
            'permissions' => $step === 'ok' ? $this->roles->permissionsFor($user) : [],
        ];
    }
}
