<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\PasswordPolicy;
use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Audit\AuditLogger;
use App\Shared\Notifications\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SEG-4 · SEG-5 · Seguridad de la propia cuenta: sesiones abiertas, cierre remoto y cambio de clave.
 */
final class AccountSecurityController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PasswordPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function sessions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $this->sessions->resolve($request);
        $rows = UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where(fn ($q) => $q->where('last_seen_at', '>=', now()->subMinutes($this->sessions->idleMinutes()))->orWhereNull('last_seen_at'))
            ->orderByDesc('last_seen_at')->get();

        return response()->json(['data' => [
            'sessions' => $rows->map(fn (UserSession $s) => [
                'id' => $s->id,
                'device' => (string) ($s->device_label ?? SessionManager::deviceLabel((string) $s->user_agent)),
                'ip' => $s->ip,
                'current' => $current !== null && $current->id === $s->id,
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
                'expires_at' => $s->expires_at->toIso8601String(),
            ])->values()->all(),
            'idle_minutes' => $this->sessions->idleMinutes(),
            'lifetime_minutes' => $this->sessions->lifetimeMinutes(),
            'password_min_length' => $this->policy->minLength(),
        ]]);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $user = $this->user($request);
        /** @var UserSession $session */
        $session = UserSession::query()->where('user_id', $user->id)->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $this->sessions->revoke($session);
        $this->activity($user, 'auth.session_revoked', ['session_id' => $id]);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * Cambiar la propia clave: exige la actual, aplica la política y cierra las demás sesiones.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', 'max:200'],
        ]);
        if (! Hash::check((string) $data['current_password'], (string) $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => [(string) trans('identity.password.current_wrong')]]);
        }
        $this->policy->assertAcceptable($user, (string) $data['password']);
        $current = $this->sessions->resolve($request);

        DB::transaction(function () use ($user, $data, $current): void {
            $hash = Hash::make((string) $data['password']);
            $this->policy->remember($user, $hash);
            $user->forceFill(['password_hash' => $hash, 'password_changed_at' => now(), 'must_change_password' => false])->save();
            // SEG-4: al cambiar la clave se cierran las demás sesiones.
            $this->sessions->revokeAllFor($user, $current?->id);
            $this->activity($user, 'auth.password_changed', []);
            $this->audit->record('user.password_changed', 'user', $user->id, null, ['password_changed_at' => now()->toIso8601String()]);
            $this->notifier->notify('account.security', [$user->id], ['event' => (string) trans('identity.password.changed_event')], 'user', $user->id);
        });

        return response()->json(['data' => ['password_changed' => true]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function activity(User $user, string $activity, array $meta): void
    {
        DB::table('activity_events')->insert([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'activity' => $activity, 'subject_type' => 'user', 'subject_id' => $user->id,
            'metadata' => json_encode($meta + ['ip' => request()->ip()]), 'occurred_at' => now(),
        ]);
    }
}
