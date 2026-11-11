<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\CodeSignIn;
use App\Modules\Identity\Application\Mfa\MfaService;
use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Domain\LoginDocument;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * SEG-2 · Ingreso y activación con documento + código por mensaje (personal sin correo).
 */
final class CodeSignInController
{
    public function __construct(
        private readonly CodeSignIn $codes,
        private readonly SessionManager $sessions,
        private readonly MfaService $mfa,
    ) {}

    /** Siempre responde lo mismo: no revela si el documento está registrado. */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate($this->documentRules());
        $this->codes->requestLoginCode((string) $data['document_type'], (string) $data['document_number']);

        return response()->json(['data' => ['sent' => true, 'channel' => $this->codes->channel()]]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate($this->documentRules() + ['code' => ['required', 'string', 'max:12']]);
        $user = $this->codes->loginWithCode((string) $data['document_type'], (string) $data['document_number'], (string) $data['code']);
        if ($user === null) {
            return ProblemDetailsResponse::make(422, 'invalid_credentials', null, null, [
                ['field' => 'code', 'rule' => 'invalid', 'message' => (string) trans('identity.codes.invalid')],
            ]);
        }

        return $this->signedIn($user, $request);
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate($this->documentRules() + [
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'max:200', 'confirmed', Password::min(1)],
        ]);
        $user = $this->codes->activateWithCode((string) $data['document_type'], (string) $data['document_number'], (string) $data['code'], (string) $data['password']);
        if ($user === null) {
            return ProblemDetailsResponse::make(422, 'invalid_token', null, null, [
                ['field' => 'code', 'rule' => 'invalid', 'message' => (string) trans('identity.codes.invalid')],
            ]);
        }

        // Activar la cuenta abre la sesión (ME-02: activar_cuenta → crea sesión).
        return $this->signedIn($user, $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:'.implode(',', LoginDocument::TYPES)],
            'document_number' => ['required', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    private function signedIn(User $user, Request $request): JsonResponse
    {
        ['session' => $session, 'token' => $token] = $this->sessions->open($user, $request);
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
}
