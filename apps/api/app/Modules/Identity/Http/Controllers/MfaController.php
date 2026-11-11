<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Mfa\MfaService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use App\Shared\Notifications\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEG-3 · Segundo factor de la propia cuenta y segundo paso del ingreso.
 */
final class MfaController
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly Notifier $notifier,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->mfa->summary($user) + ['step' => $this->mfa->sessionStatus($user, $this->session($request))]]);
    }

    public function startTotp(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $tenant = DB::table('current_tenant')->value('name');
        $account = trim((is_string($tenant) ? $tenant.' · ' : '').$user->display_name);

        return response()->json(['data' => $this->mfa->startEnrollment($user, $account)]);
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $codes = $this->mfa->confirmEnrollment($user, (string) $data['code'], $this->session($request));
        $this->notifier->notify('account.security', [$user->id], ['event' => (string) trans('identity.mfa.enabled_event')], 'user', $user->id);

        return response()->json(['data' => ['enabled' => true, 'recovery_codes' => $codes]]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $session = $this->session($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        abort_unless($session !== null, 401);

        if ($session->mfa_passed) {
            return response()->json(['data' => ['verified' => true]]);
        }
        if (! $this->mfa->verify($user, $session, (string) $data['code'])) {
            return ProblemDetailsResponse::make(422, 'validation_failed', null, null, [
                ['field' => 'code', 'rule' => 'invalid', 'message' => (string) trans('identity.mfa.invalid_code')],
            ]);
        }

        return response()->json(['data' => ['verified' => true, 'recovery_codes_left' => $this->mfa->summary($user)['recovery_codes_left']]]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => ['recovery_codes' => $this->mfa->regenerateRecoveryCodes($user, (string) $data['password'])]]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);
        $this->mfa->disable($user, (string) $data['password']);
        $this->notifier->notify('account.security', [$user->id], ['event' => (string) trans('identity.mfa.disabled_event')], 'user', $user->id);

        return response()->json(['data' => ['enabled' => false]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function session(Request $request): ?UserSession
    {
        $session = $request->attributes->get('ayni_session');

        return $session instanceof UserSession ? $session : null;
    }
}
