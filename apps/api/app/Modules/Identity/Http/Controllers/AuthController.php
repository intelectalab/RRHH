<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\LoginWithPassword;
use App\Modules\Identity\Application\Actions\RequestPasswordReset;
use App\Modules\Identity\Application\Actions\ResetPassword;
use App\Modules\Identity\Application\Mfa\MfaService;
use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Application\UserService;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly MfaService $mfa,
    ) {}

    public function login(LoginRequest $request, LoginWithPassword $login): JsonResponse
    {
        [$kind, $identifier] = match (true) {
            $request->filled('email') => ['email', (string) $request->input('email')],
            $request->filled('login') => ['username', (string) $request->input('login')],
            default => ['national_id', (string) $request->input('document_type').':'.(string) $request->input('document_number')],
        };

        $user = $login($kind, $identifier, (string) $request->input('password'));

        if ($user === null) {
            return ProblemDetailsResponse::make(422, 'invalid_credentials', null, null, [
                ['field' => 'credentials', 'rule' => 'invalid', 'message' => (string) trans('errors.invalid_credentials')],
            ]);
        }

        ['session' => $session, 'token' => $token] = $this->sessions->open($user, $request);

        $response = response()->json(['data' => $this->payload($user, $session)]);
        foreach ($this->sessions->cookies($token, $session) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $request->attributes->get('ayni_session');
        if ($session instanceof UserSession) {
            $this->sessions->revoke($session);
        }

        $response = response()->json(['data' => ['signed_out' => true]]);
        foreach ($this->sessions->forgetCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public function forgotPassword(ForgotPasswordRequest $request, RequestPasswordReset $action): JsonResponse
    {
        $action((string) $request->input('email'), $request->getSchemeAndHttpHost());

        // Misma respuesta exista o no el correo (SEG-1).
        return response()->json(['data' => ['message' => (string) trans('identity.password_reset.requested')]]);
    }

    public function resetPassword(ResetPasswordRequest $request, ResetPassword $action): JsonResponse
    {
        if (! $action((string) $request->input('token'), (string) $request->input('password'))) {
            return ProblemDetailsResponse::make(422, 'invalid_token', null, null, [
                ['field' => 'token', 'rule' => 'invalid', 'message' => (string) trans('errors.invalid_token')],
            ]);
        }

        return response()->json(['data' => ['password_reset' => true]]);
    }

    public function acceptInvitation(ResetPasswordRequest $request, UserService $users): JsonResponse
    {
        $user = $users->acceptInvitation((string) $request->input('token'), (string) $request->input('password'));
        if ($user === null) {
            return ProblemDetailsResponse::make(422, 'invalid_token', null, null, [
                ['field' => 'token', 'rule' => 'invalid', 'message' => (string) trans('errors.invalid_token')],
            ]);
        }

        // Activar la cuenta abre la sesión (ME-02: activar_cuenta → crea sesión).
        ['session' => $session, 'token' => $token] = $this->sessions->open($user, $request);
        $response = response()->json(['data' => $this->payload($user, $session)]);
        foreach ($this->sessions->cookies($token, $session) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, UserSession $session): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'display_name' => $user->display_name,
                'locale' => $user->locale,
                'must_change_password' => $user->must_change_password,
            ],
            'session' => [
                'id' => $session->id,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
            // SEG-3: ok, verify (pedir el código) o enroll (configurarlo antes de operar).
            'mfa_step' => $this->mfa->sessionStatus($user, $session),
        ];
    }
}
