<?php

namespace App\Modules\Platform\Application\Support;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Security\SupportContext;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SAS-8 · Sesiones de soporte con suplantación (RN-SAS-08, RN-SAS-12).
 *
 * - Exigen motivo, un usuario activo de la organización y que la organización tenga el acceso de soporte habilitado.
 * - Duran como máximo support.max_session_minutes y caducan solas; el ingreso es por un enlace de un solo uso.
 * - Sin autorización «full» vigente del cliente, la sesión no ve campos sensibles (sueldos, salud, denuncias…).
 * - Inicio, fin y acciones quedan en la auditoría de la organización (marca «soporte») y en la de la consola.
 * - La consola ve de las acciones solo qué se hizo y cuándo, nunca los datos.
 */
final class SupportSessions
{
    public const ACTIVE_STATES = ['pending', 'active'];

    public function __construct(
        private readonly ConsoleAudit $consoleAudit,
        private readonly PlatformSettings $settings,
        private readonly SupportContext $support,
    ) {}

    public function maxMinutes(): int
    {
        return max(5, min(480, (int) ($this->settings->value('support.max_session_minutes') ?? 60)));
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(?string $tenantId, ?string $status, int $page, int $perPage): array
    {
        $query = $this->base()
            ->when($tenantId !== null, fn ($q) => $q->where('s.tenant_id', $tenantId))
            ->when($status === 'expired', fn ($q) => $q->whereIn('s.status', self::ACTIVE_STATES)->where('s.expires_at', '<=', now()))
            ->when($status !== null && $status !== 'expired' && in_array($status, self::ACTIVE_STATES, true), fn ($q) => $q->where('s.status', $status)->where('s.expires_at', '>', now()))
            ->when($status === 'ended', fn ($q) => $q->whereIn('s.status', ['ended', 'expired']));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('s.created_at')->forPage($page, $perPage)->get();

        return ['data' => $rows->map(fn ($r): array => $this->present($r))->values()->all(), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $row = $this->find($id);

        return $this->present($row) + ['actions' => $this->actions($row)];
    }

    /**
     * Usuarios de la organización para elegir a quién suplantar: solo nombre, roles y estado (RN-SAS-12).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tenantUsers(string $tenantId): array
    {
        $this->tenant($tenantId);

        return TenancyScope::runAs($tenantId, function (): array {
            $roles = DB::table('user_roles AS ur')->join('roles AS r', 'r.id', '=', 'ur.role_id')
                ->whereNull('r.deleted_at')->get(['ur.user_id', 'r.name'])->groupBy('user_id');

            return DB::table('users')->whereNull('deleted_at')->orderBy('display_name')->limit(500)
                ->get(['id', 'display_name', 'status'])
                ->map(fn ($u): array => [
                    'id' => (string) $u->id,
                    'display_name' => (string) $u->display_name,
                    'status' => (string) $u->status,
                    'roles' => ($roles[$u->id] ?? collect())->pluck('name')->map(fn ($n): string => (string) $n)->values()->all(),
                ])->values()->all();
        });
    }

    /**
     * @param  array{tenant_id: string, target_user_id: string, reason: string, ticket_reference?: string|null, duration_minutes?: int|null}  $input
     * @return array<string, mixed>
     */
    public function create(array $input, PlatformUser $actor, Request $request): array
    {
        $tenant = $this->tenant($input['tenant_id']);
        if (! in_array((string) $tenant->status, ['trial', 'active', 'grace_period', 'suspended', 'blocked'], true)) {
            throw new AyniException('tenant_not_operable', 409, (string) trans('platform.support.tenant_not_operable'));
        }
        $minutes = (int) ($input['duration_minutes'] ?? $this->maxMinutes());
        if ($minutes < 5 || $minutes > $this->maxMinutes()) {
            throw ValidationException::withMessages(['duration_minutes' => [(string) trans('platform.support.duration', ['max' => $this->maxMinutes()])]]);
        }

        $tenantId = (string) $tenant->id;
        $actorId = (string) $actor->id;
        [$enabled, $userOk, $grant] = TenancyScope::runAs($tenantId, function () use ($input, $actorId): array {
            $enabled = (bool) (app(ParameterResolver::class)->value('seguridad.acceso_soporte') ?? true);
            $userOk = TenancyScope::isValidUuid($input['target_user_id'])
                && DB::table('users')->where('id', $input['target_user_id'])->whereNull('deleted_at')->where('status', 'active')->exists();
            // Autorización vigente del cliente: «full» deja ver campos sensibles.
            $grant = DB::table('support_access_grants')->whereNull('revoked_at')
                ->where('starts_at', '<=', now())->where('expires_at', '>', now())
                ->where(fn ($q) => $q->whereNull('platform_user_id')->orWhere('platform_user_id', $actorId))
                ->orderByRaw("CASE access_level WHEN 'full' THEN 0 ELSE 1 END")->first(['id', 'access_level']);

            return [$enabled, $userOk, $grant];
        });
        if (! $enabled) {
            throw new AyniException('support_access_disabled', 409, (string) trans('platform.support.access_disabled'));
        }
        if (! $userOk) {
            throw ValidationException::withMessages(['target_user_id' => [(string) trans('platform.support.user_not_found')]]);
        }

        $token = Str::random(48);
        $id = (string) Str::uuid();
        $expires = now()->addMinutes($minutes);
        DB::table('support_sessions')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'platform_user_id' => $actorId,
            'agent_name' => (string) $actor->full_name,
            'target_user_id' => $input['target_user_id'],
            'reason' => $input['reason'],
            'ticket_reference' => $input['ticket_reference'] ?? null,
            'status' => 'pending',
            'allow_sensitive' => $grant !== null && $grant->access_level === 'full',
            'support_access_grant_id' => $grant?->id,
            'entry_token_hash' => hash('sha256', $token),
            'expires_at' => $expires,
        ]);
        $this->consoleAudit->record('support_session.created', $actor, 'support_session', $id, null, [
            'reason' => $input['reason'], 'ticket_reference' => $input['ticket_reference'] ?? null,
            'target_user_id' => $input['target_user_id'], 'expires_at' => $expires->toIso8601String(),
            'allow_sensitive' => $grant !== null && $grant->access_level === 'full',
        ], $tenantId);

        return $this->show($id) + ['entry_url' => $this->entryUrl((string) $tenant->slug, $token, $request)];
    }

    /**
     * @return array<string, mixed>
     */
    public function end(string $id, PlatformUser $actor, ?string $reason): array
    {
        $row = $this->find($id);
        if (! in_array((string) $row->status, self::ACTIVE_STATES, true)) {
            throw new InvalidTransitionException((string) $row->status);
        }
        DB::table('support_sessions')->where('id', $id)->update([
            'status' => 'ended', 'ended_at' => now(), 'ended_by_platform_user_id' => $actor->id, 'entry_token_hash' => null,
        ]);
        $tenantId = (string) $row->tenant_id;
        TenancyScope::runAs($tenantId, function () use ($row, $actor, $reason): void {
            if ($row->user_session_id !== null) {
                DB::table('user_sessions')->where('id', $row->user_session_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            if ($row->started_at !== null) {
                $this->support->during([
                    'session_id' => (string) $row->id, 'platform_user_id' => (string) $actor->id, 'agent_name' => (string) $actor->full_name,
                    'allow_sensitive' => (bool) $row->allow_sensitive, 'expires_at' => Carbon::parse((string) $row->expires_at)->toIso8601String(),
                ], fn () => app(AuditLogger::class)->record('support.session_ended', 'support_session', (string) $row->id, ['status' => 'active'], ['status' => 'ended'], $reason));
            }
        });
        $this->consoleAudit->record('support_session.ended', $actor, 'support_session', $id, ['status' => (string) $row->status], ['status' => 'ended', 'reason' => $reason], $tenantId);

        return $this->show($id);
    }

    /**
     * Enlace al host de la organización con el token en el fragmento (no viaja a los registros del servidor).
     */
    private function entryUrl(string $slug, string $token, Request $request): string
    {
        $origin = (string) ($request->headers->get('Origin') ?: $request->getSchemeAndHttpHost());
        $parts = parse_url($origin) ?: [];
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$slug.'.'.config('tenancy.base_domain').$port.'/soporte/entrar#t='.$token;
    }

    /**
     * Acciones del soporte en la organización durante la sesión: qué y cuándo, nunca los datos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function actions(\stdClass $row): array
    {
        if ($row->started_at === null) {
            return [];
        }
        $until = $row->ended_at ?? $row->expires_at;

        return TenancyScope::runAs((string) $row->tenant_id, fn (): array => DB::table('audit_events')
            ->where('actor_kind', 'platform_support')
            ->where('occurred_at', '>=', $row->started_at)->where('occurred_at', '<=', $until)
            ->where(fn ($q) => $q->where('actor_user_id', $row->target_user_id)->orWhere('entity_id', $row->id))
            ->orderBy('occurred_at')->limit(500)
            ->get(['action', 'entity_type', 'occurred_at'])
            ->map(fn ($a): array => ['action' => (string) $a->action, 'entity_type' => (string) $a->entity_type, 'occurred_at' => Carbon::parse((string) $a->occurred_at)->toIso8601String()])
            ->values()->all());
    }

    private function base(): Builder
    {
        return DB::table('support_sessions AS s')
            ->join('tenants AS t', 't.id', '=', 's.tenant_id')
            ->join('platform_users AS u', 'u.id', '=', 's.platform_user_id')
            ->select(['s.*', 't.name AS tenant_name', 't.slug AS tenant_slug', 'u.full_name AS agent_name', 'u.email AS agent_email']);
    }

    private function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? $this->base()->where('s.id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $row;
    }

    private function tenant(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('tenants')->where('id', $id)->whereNull('deleted_at')->first(['id', 'slug', 'status']) : null;
        if (! $row instanceof \stdClass) {
            throw ValidationException::withMessages(['tenant_id' => [(string) trans('platform.support.tenant_not_found')]]);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $r): array
    {
        $status = in_array((string) $r->status, self::ACTIVE_STATES, true) && Carbon::parse((string) $r->expires_at)->isPast() ? 'expired' : (string) $r->status;
        $targetName = TenancyScope::runAs((string) $r->tenant_id, fn () => DB::table('users')->where('id', $r->target_user_id)->value('display_name'));

        return [
            'id' => (string) $r->id,
            'tenant_id' => (string) $r->tenant_id,
            'tenant' => ['name' => (string) $r->tenant_name, 'slug' => (string) $r->tenant_slug],
            'agent' => ['id' => (string) $r->platform_user_id, 'name' => (string) $r->agent_name, 'email' => (string) $r->agent_email],
            'target_user_id' => (string) $r->target_user_id,
            'target_user' => ['display_name' => $targetName === null ? null : (string) $targetName],
            'reason' => (string) $r->reason,
            'ticket_reference' => $r->ticket_reference,
            'status' => $status,
            'allow_sensitive' => (bool) $r->allow_sensitive,
            'expires_at' => Carbon::parse((string) $r->expires_at)->toIso8601String(),
            'started_at' => $r->started_at === null ? null : Carbon::parse((string) $r->started_at)->toIso8601String(),
            'ended_at' => $r->ended_at === null ? null : Carbon::parse((string) $r->ended_at)->toIso8601String(),
            'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse((string) $r->updated_at)->toIso8601String(),
            'allowed_actions' => in_array($status, self::ACTIVE_STATES, true) ? ['end'] : [],
        ];
    }
}
