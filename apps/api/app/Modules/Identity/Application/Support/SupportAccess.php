<?php

namespace App\Modules\Identity\Application\Support;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Security\SupportContext;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SAS-8 · Acceso del soporte de GestiaHR, del lado de la organización (RN-SAS-08):
 *
 * - El administrador lo habilita o lo desactiva por completo (parámetro seguridad.acceso_soporte).
 * - Puede autorizar al soporte por un tiempo: «read_only» (sin campos sensibles) o «full» (los ve).
 * - La consola crea la sesión de soporte con motivo y un enlace de un solo uso; al usarlo aquí se abre una
 *   sesión marcada como de soporte, que caduca sola y cuyas acciones quedan en la auditoría como «soporte».
 */
final class SupportAccess
{
    public const PARAMETER = 'seguridad.acceso_soporte';

    public function __construct(
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
        private readonly SupportContext $support,
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->parameters->value(self::PARAMETER) ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'enabled' => $this->enabled(),
            'grants' => $this->grants(),
            'sessions' => $this->sessions(),
        ];
    }

    /**
     * Desactivarlo cierra en el acto las sesiones de soporte abiertas.
     */
    public function setEnabled(bool $enabled, string $reason): void
    {
        $before = $this->enabled();
        if ($before === $enabled) {
            return;
        }
        DB::transaction(function () use ($enabled, $before, $reason): void {
            $this->parameters->set(self::PARAMETER, $enabled, 'organization', null, null, $reason);
            if (! $enabled) {
                $open = DB::table('support_sessions')->where('tenant_id', TenancyScope::requireTenant())
                    ->whereIn('status', ['pending', 'active'])->get(['id', 'user_session_id']);
                foreach ($open as $row) {
                    DB::table('support_sessions')->where('id', $row->id)->update(['status' => 'ended', 'ended_at' => now(), 'entry_token_hash' => null]);
                    if ($row->user_session_id !== null) {
                        DB::table('user_sessions')->where('id', $row->user_session_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                    }
                }
            }
            $this->audit->record('support.access_changed', 'tenant', TenancyScope::requireTenant(), ['enabled' => $before], ['enabled' => $enabled], $reason);
        });
    }

    /**
     * @param  array{reason: string, access_level: string, expires_at: string}  $input
     * @return array<string, mixed>
     */
    public function grant(array $input, User $by): array
    {
        $expires = Carbon::parse($input['expires_at']);
        $id = (string) Str::uuid();
        DB::table('support_access_grants')->insert([
            'id' => $id,
            'tenant_id' => TenancyScope::requireTenant(),
            'granted_by_user_id' => $by->id,
            'platform_user_id' => null,
            'reason' => $input['reason'],
            'access_level' => $input['access_level'],
            'starts_at' => now(),
            'expires_at' => $expires,
        ]);
        $grant = $this->presentGrant($this->findGrant($id));
        $this->audit->record('support.access_granted', 'support_access_grant', $id, null, [
            'access_level' => $input['access_level'], 'expires_at' => $grant['expires_at'],
        ], $input['reason']);

        return $grant;
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(string $id, User $by, ?string $reason): array
    {
        $row = $this->findGrant($id);
        if ($row->revoked_at !== null || Carbon::parse((string) $row->expires_at)->isPast()) {
            throw new AyniException('invalid_transition', 409, (string) trans('identity.support.grant_not_active'));
        }
        DB::table('support_access_grants')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by_user_id' => $by->id]);
        $this->audit->record('support.access_revoked', 'support_access_grant', $id, ['revoked' => false], ['revoked' => true], $reason);

        return $this->presentGrant($this->findGrant($id));
    }

    /**
     * Abre la sesión de soporte con el enlace de un solo uso.
     *
     * @return array{session: UserSession, token: string}
     */
    public function enter(string $entryToken, Request $request): array
    {
        $tenantId = TenancyScope::requireTenant();
        $row = DB::table('support_sessions')->where('tenant_id', $tenantId)
            ->where('entry_token_hash', hash('sha256', $entryToken))
            ->where('status', 'pending')->whereNull('entry_used_at')
            ->where('expires_at', '>', now())->first();
        if ($row === null) {
            throw new AyniException('support_link_invalid', 422, (string) trans('identity.support.link_invalid'));
        }
        if (! $this->enabled()) {
            DB::table('support_sessions')->where('id', $row->id)->update(['status' => 'ended', 'ended_at' => now(), 'entry_token_hash' => null]);
            throw new AyniException('support_access_disabled', 422, (string) trans('identity.support.disabled'));
        }
        /** @var User|null $user */
        $user = User::query()->find($row->target_user_id);
        if ($user === null || ! $user->canSignIn()) {
            throw new AyniException('support_link_invalid', 422, (string) trans('identity.support.link_invalid'));
        }

        $token = Str::random(64);
        $session = DB::transaction(function () use ($row, $user, $token, $request): UserSession {
            $session = UserSession::create([
                'user_id' => $user->id,
                'session_hash' => hash('sha256', $token),
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'last_seen_at' => now(),
                'device_label' => (string) trans('identity.support.device_label'),
                // El soporte ya pasó el segundo factor de la consola; la sesión dura lo que la de soporte.
                'mfa_passed' => true,
                'expires_at' => Carbon::parse((string) $row->expires_at),
                'impersonated_by_platform_user_id' => $row->platform_user_id,
            ]);
            DB::table('support_sessions')->where('id', $row->id)->update([
                'status' => 'active', 'started_at' => now(), 'entry_used_at' => now(),
                'entry_token_hash' => null, 'user_session_id' => $session->id,
            ]);
            $context = $this->contextFor((string) $row->id);
            if ($context !== null) {
                $this->support->during($context, fn () => $this->audit->record('support.session_started', 'support_session', (string) $row->id, null, [
                    'agent' => $context['agent_name'], 'user_id' => $user->id, 'expires_at' => $context['expires_at'],
                    'allow_sensitive' => $context['allow_sensitive'],
                ], (string) $row->reason));
            }

            return $session;
        });

        return ['session' => $session, 'token' => $token];
    }

    /**
     * Datos de la sesión de soporte ligada a una sesión web suplantada; null si ya no vale (vencida o cerrada).
     *
     * @return array{session_id: string, platform_user_id: string, agent_name: string, allow_sensitive: bool, expires_at: string}|null
     */
    public function contextForUserSession(UserSession $session): ?array
    {
        $id = DB::table('support_sessions')->where('user_session_id', $session->id)
            ->where('tenant_id', TenancyScope::requireTenant())
            ->where('status', 'active')->where('expires_at', '>', now())->value('id');

        return $id === null ? null : $this->contextFor((string) $id);
    }

    /**
     * @return array{session_id: string, platform_user_id: string, agent_name: string, allow_sensitive: bool, expires_at: string}|null
     */
    private function contextFor(string $supportSessionId): ?array
    {
        $row = DB::table('support_sessions')->where('id', $supportSessionId)
            ->first(['id', 'platform_user_id', 'allow_sensitive', 'expires_at', 'agent_name']);
        if ($row === null) {
            return null;
        }

        return [
            'session_id' => (string) $row->id,
            'platform_user_id' => (string) $row->platform_user_id,
            'agent_name' => (string) $row->agent_name,
            'allow_sensitive' => (bool) $row->allow_sensitive,
            'expires_at' => Carbon::parse((string) $row->expires_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function grants(): array
    {
        return DB::table('support_access_grants')->orderByDesc('starts_at')->limit(50)->get()
            ->map(fn ($r): array => $this->presentGrant($r))->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sessions(): array
    {
        return DB::table('support_sessions AS s')
            ->where('s.tenant_id', TenancyScope::requireTenant())
            ->orderByDesc('s.created_at')->limit(50)
            ->get(['s.id', 's.reason', 's.ticket_reference', 's.status', 's.allow_sensitive', 's.target_user_id', 's.expires_at', 's.started_at', 's.ended_at', 's.created_at', 's.agent_name'])
            ->map(fn ($r): array => [
                'id' => (string) $r->id,
                'agent_name' => (string) $r->agent_name,
                'target_user_id' => (string) $r->target_user_id,
                'target_user_name' => User::query()->whereKey($r->target_user_id)->value('display_name'),
                'reason' => (string) $r->reason,
                'ticket_reference' => $r->ticket_reference,
                'status' => self::status((string) $r->status, (string) $r->expires_at),
                'allow_sensitive' => (bool) $r->allow_sensitive,
                'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
                'started_at' => $r->started_at === null ? null : Carbon::parse((string) $r->started_at)->toIso8601String(),
                'ended_at' => $r->ended_at === null ? null : Carbon::parse((string) $r->ended_at)->toIso8601String(),
                'expires_at' => Carbon::parse((string) $r->expires_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * Una sesión pendiente o activa cuyo plazo pasó se muestra vencida aunque nadie la haya cerrado.
     */
    public static function status(string $status, string $expiresAt): string
    {
        return in_array($status, ['pending', 'active'], true) && Carbon::parse($expiresAt)->isPast() ? 'expired' : $status;
    }

    private function findGrant(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('support_access_grants')->where('id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGrant(\stdClass $r): array
    {
        $expires = Carbon::parse((string) $r->expires_at);

        return [
            'id' => (string) $r->id,
            'reason' => (string) $r->reason,
            'access_level' => (string) $r->access_level,
            'granted_by_name' => User::query()->whereKey($r->granted_by_user_id)->value('display_name'),
            'starts_at' => Carbon::parse((string) $r->starts_at)->toIso8601String(),
            'expires_at' => $expires->toIso8601String(),
            'revoked_at' => $r->revoked_at === null ? null : Carbon::parse((string) $r->revoked_at)->toIso8601String(),
            'active' => $r->revoked_at === null && $expires->isFuture(),
        ];
    }
}
