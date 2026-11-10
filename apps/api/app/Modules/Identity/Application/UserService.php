<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Actions\RequestPasswordReset;
use App\Modules\Identity\Domain\UserAccountStateMachine;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Identity\Infrastructure\Models\UserToken;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\BlindIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SEG-6 · Usuarios e invitaciones. El usuario es la entidad de acceso, separada de la
 * persona y de la relación laboral: suspenderlo no toca la ficha ni la historia.
 */
final class UserService
{
    public function __construct(
        private readonly UserAccountStateMachine $machine,
        private readonly OneTimeTokens $tokens,
        private readonly SessionManager $sessions,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
        private readonly PermissionResolver $permissions,
        private readonly RequestPasswordReset $passwordReset,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $identities = $user->identities()->get();
        $login = $identities->firstWhere('kind', 'username') ?? $identities->firstWhere('kind', 'email');
        $email = $identities->firstWhere('kind', 'email');

        return [
            'id' => $user->id,
            'person_id' => $user->person_id,
            'login' => $login?->identifier() ?? '',
            'display_name' => $user->display_name,
            'email' => $email?->identifier(),
            'status' => $user->status,
            'mfa_enabled' => DB::table('user_mfa_factors')->where('user_id', $user->id)->whereNotNull('confirmed_at')->whereNull('disabled_at')->exists(),
            'role_ids' => $user->roleAssignments()->orderBy('created_at')->pluck('role_id')->map(fn ($id) => (string) $id)->all(),
            'role_assignments' => $this->assignmentsOf($user),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'allowed_actions' => $this->machine->allowedActions($user->status),
            'version' => (int) $user->lock_version,
            'created_at' => $user->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $user->getAttribute('updated_at')?->toIso8601String(),
        ];
    }

    /**
     * @param  array{login: string, display_name?: string, email?: string|null, person_id?: string|null, role_ids?: array<int, string>}  $input
     */
    public function create(array $input, EffectivePermissions $actor, string $origin): User
    {
        $roleIds = $this->validatedRoles($input['role_ids'] ?? [], $actor);
        $this->guardUnique('username', $input['login']);
        if (! empty($input['email'])) {
            $this->guardUnique('email', $input['email']);
        }

        return DB::transaction(function () use ($input, $roleIds, $origin): User {
            $user = User::create([
                'display_name' => $input['display_name'] ?? $input['login'],
                'person_id' => $input['person_id'] ?? null,
                'status' => User::STATUS_INVITED,
                'locale' => null,
            ]);
            $user->identities()->create(UserIdentity::attributesFor('username', $input['login']) + ['is_primary' => empty($input['email'])]);
            if (! empty($input['email'])) {
                $user->identities()->create(UserIdentity::attributesFor('email', $input['email']) + ['is_primary' => true]);
            }
            foreach ($roleIds as $roleId) {
                $user->roleAssignments()->create(['role_id' => $roleId, 'granted_by_user_id' => $this->actorId()]);
            }

            if (! empty($input['email'])) {
                $this->issueInvitation($user, $origin);
            }

            $this->audit->record('user.created', 'user', $user->id, null, $this->present($user->refresh()));

            return $user;
        });
    }

    /**
     * @param  array{login?: string, display_name?: string, email?: string|null, person_id?: string|null, role_ids?: array<int, string>, role_assignments?: array<int, mixed>}  $input
     */
    public function update(User $user, array $input, EffectivePermissions $actor, ?int $expectedVersion): User
    {
        if ($expectedVersion !== null && $expectedVersion !== (int) $user->lock_version) {
            throw new VersionConflictException($expectedVersion, (int) $user->lock_version);
        }
        $assignments = array_key_exists('role_assignments', $input) ? $this->validatedAssignments((array) $input['role_assignments']) : null;
        if ($assignments !== null) {
            $input['role_ids'] = array_column($assignments, 'role_id');
        }
        $roleIds = array_key_exists('role_ids', $input) ? $this->validatedRoles($input['role_ids'], $actor) : null;

        return DB::transaction(function () use ($user, $input, $roleIds, $assignments): User {
            $before = $this->present($user);

            $user->fill(array_intersect_key($input, array_flip(['display_name', 'person_id'])));
            if ($user->isDirty()) {
                $user->save();
            }

            if (isset($input['login']) && $input['login'] !== $before['login']) {
                $this->guardUnique('username', $input['login'], $user->id);
                $user->identities()->where('kind', 'username')->delete();
                $user->identities()->create(UserIdentity::attributesFor('username', $input['login']));
            }
            if (array_key_exists('email', $input) && $input['email'] !== $before['email']) {
                $user->identities()->where('kind', 'email')->delete();
                if (! empty($input['email'])) {
                    $this->guardUnique('email', $input['email'], $user->id);
                    $user->identities()->create(UserIdentity::attributesFor('email', $input['email']) + ['is_primary' => true]);
                }
            }

            if ($roleIds !== null) {
                $removed = array_diff((array) $before['role_ids'], $roleIds);
                if ($removed !== [] && $this->isActiveAdmin($user) && ! $this->keepsAdminRole($roleIds)) {
                    $this->guardNotLastAdmin($user);
                }
                $user->roleAssignments()->whereNotIn('role_id', $roleIds)->delete();
                $existing = $user->roleAssignments()->pluck('role_id')->map(fn ($id) => (string) $id)->all();
                foreach (array_diff($roleIds, $existing) as $roleId) {
                    $user->roleAssignments()->create(['role_id' => $roleId, 'granted_by_user_id' => $this->actorId()]);
                }
                if ($assignments !== null) {
                    $this->syncScopes($user, $assignments);
                }
                DB::table('users')->where('id', $user->id)->update(['updated_at' => now()]);
            }

            $user->refresh();
            $this->audit->record('user.updated', 'user', $user->id, $before, $this->present($user));
            $this->permissions->forget();

            return $user;
        });
    }

    public function invite(User $user, string $origin): User
    {
        $this->machine->getNextState($user->status, UserAccountStateMachine::INVITE);
        if ($user->identities()->where('kind', 'email')->doesntExist()) {
            throw new BusinessRuleViolatedException('RN-SEG-09', (string) trans('identity.users.invite_without_email'));
        }

        return DB::transaction(function () use ($user, $origin): User {
            $this->issueInvitation($user, $origin);
            $this->audit->record('user.invited', 'user', $user->id, null, ['status' => $user->status]);

            return $user;
        });
    }

    public function suspend(User $user, ?string $reason): User
    {
        $next = $this->machine->getNextState($user->status, UserAccountStateMachine::SUSPEND);
        $this->guardNotLastAdmin($user);

        return DB::transaction(function () use ($user, $next, $reason): User {
            $before = $this->present($user);
            $user->forceFill(['status' => $next])->save();
            $this->sessions->revokeAllFor($user);
            $this->outbox->record(DomainEventTypes::USER_SUSPENDED, 'user', $user->id, ['user_id' => $user->id, 'reason' => (string) $reason]);
            $this->audit->record('user.suspended', 'user', $user->id, $before, $this->present($user->refresh()), $reason);

            return $user;
        });
    }

    public function reactivate(User $user, ?string $reason): User
    {
        $next = $this->machine->getNextState($user->status, UserAccountStateMachine::REACTIVATE);

        return DB::transaction(function () use ($user, $next, $reason): User {
            $before = $this->present($user);
            $user->forceFill(['status' => $next, 'failed_attempts' => 0, 'locked_until' => null])->save();
            $this->outbox->record(DomainEventTypes::USER_ACTIVATED, 'user', $user->id, ['user_id' => $user->id]);
            $this->audit->record('user.reactivated', 'user', $user->id, $before, $this->present($user->refresh()), $reason);

            return $user;
        });
    }

    public function resetPassword(User $user, string $origin, ?string $reason): User
    {
        if (! in_array($user->status, ['active', 'locked'], true)) {
            throw new InvalidTransitionException($user->status, $this->machine->allowedActions($user->status));
        }
        if ($user->identities()->where('kind', 'email')->doesntExist()) {
            throw new BusinessRuleViolatedException('RN-SEG-09', (string) trans('identity.users.reset_without_email'));
        }

        DB::transaction(function () use ($user, $origin, $reason): void {
            $user->forceFill(['must_change_password' => true])->save();
            $this->sessions->revokeAllFor($user);
            $this->passwordReset->issueFor($user, $origin);
            $this->audit->record('user.password_reset_forced', 'user', $user->id, null, ['must_change_password' => true], $reason);
        });

        return $user->refresh();
    }

    public function resetMfa(User $user, ?string $reason): User
    {
        if (! in_array($user->status, ['active', 'locked'], true)) {
            throw new InvalidTransitionException($user->status, $this->machine->allowedActions($user->status));
        }

        DB::transaction(function () use ($user, $reason): void {
            $factors = DB::table('user_mfa_factors')->where('user_id', $user->id)->whereNull('disabled_at');
            $count = $factors->count();
            $factors->update(['disabled_at' => now()]);
            $this->sessions->revokeAllFor($user);
            $this->audit->record('user.mfa_reset', 'user', $user->id, ['active_factors' => $count], ['active_factors' => 0], $reason);
        });

        return $user->refresh();
    }

    /**
     * El propio usuario activa su cuenta con el enlace de invitación (activar_cuenta).
     */
    public function acceptInvitation(string $raw, string $password): ?User
    {
        return DB::transaction(function () use ($raw, $password): ?User {
            $token = $this->tokens->consume($raw, UserToken::PURPOSE_INVITE);
            if ($token === null) {
                return null;
            }
            /** @var User|null $user */
            $user = User::query()->find($token->user_id);
            if ($user === null || ! $this->machine->can($user->status, UserAccountStateMachine::ACTIVATE)) {
                return null;
            }

            $user->forceFill([
                'status' => User::STATUS_ACTIVE,
                'password_hash' => Hash::make($password),
                'password_changed_at' => now(),
                'must_change_password' => false,
            ])->save();

            $this->outbox->record(DomainEventTypes::USER_ACTIVATED, 'user', $user->id, ['user_id' => $user->id]);
            DB::table('activity_events')->insert([
                'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'activity' => 'auth.account_activated',
                'subject_type' => 'user', 'subject_id' => $user->id, 'occurred_at' => now(),
            ]);

            return $user;
        });
    }

    /**
     * @return array<int, array{role_id: string, scope: string|null, legal_entity_ids: array<int, string>, site_ids: array<int, string>, org_unit_ids: array<int, string>}>
     */
    private function assignmentsOf(User $user): array
    {
        $rows = $user->roleAssignments()->orderBy('created_at')->get(['id', 'role_id', 'scope_override']);
        $scopes = DB::table('user_role_scopes')->whereIn('user_role_id', $rows->pluck('id'))->get();

        return $rows->map(fn ($r) => [
            'role_id' => (string) $r->role_id,
            'scope' => $r->scope_override === null ? null : RoleScopes::toApi((string) $r->scope_override),
            'legal_entity_ids' => $scopes->where('user_role_id', $r->id)->whereNotNull('legal_entity_id')->pluck('legal_entity_id')->map(fn ($v) => (string) $v)->values()->all(),
            'site_ids' => $scopes->where('user_role_id', $r->id)->whereNotNull('site_id')->pluck('site_id')->map(fn ($v) => (string) $v)->values()->all(),
            'org_unit_ids' => $scopes->where('user_role_id', $r->id)->whereNotNull('org_unit_id')->pluck('org_unit_id')->map(fn ($v) => (string) $v)->values()->all(),
        ])->all();
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return array<int, array{role_id: string, scope: string|null, legal_entity_ids: array<int, string>, site_ids: array<int, string>, org_unit_ids: array<int, string>}>
     */
    private function validatedAssignments(array $raw): array
    {
        $out = [];
        $errors = [];
        foreach ($raw as $i => $a) {
            $a = (array) $a;
            $item = [
                'role_id' => (string) ($a['role_id'] ?? ''),
                'scope' => isset($a['scope']) ? (string) $a['scope'] : null,
                'legal_entity_ids' => array_values(array_map('strval', (array) ($a['legal_entity_ids'] ?? []))),
                'site_ids' => array_values(array_map('strval', (array) ($a['site_ids'] ?? []))),
                'org_unit_ids' => array_values(array_map('strval', (array) ($a['org_unit_ids'] ?? []))),
            ];
            $needs = ['legal_entity' => 'legal_entity_ids', 'site' => 'site_ids', 'unit' => 'org_unit_ids'];
            if (isset($needs[$item['scope'] ?? '']) && $item[$needs[$item['scope']]] === []) {
                $errors["role_assignments.{$i}.{$needs[$item['scope']]}"][] = (string) trans('identity.users.scope_units_required');
            }
            foreach (['legal_entity_ids' => 'legal_entities', 'site_ids' => 'sites', 'org_unit_ids' => 'org_units'] as $key => $table) {
                if ($item[$key] !== [] && DB::table($table)->whereIn('id', $item[$key])->count() !== count(array_unique($item[$key]))) {
                    $errors["role_assignments.{$i}.{$key}"][] = (string) trans('identity.users.unknown_scope_unit');
                }
            }
            $out[] = $item;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * @param  array<int, array{role_id: string, scope: string|null, legal_entity_ids: array<int, string>, site_ids: array<int, string>, org_unit_ids: array<int, string>}>  $assignments
     */
    private function syncScopes(User $user, array $assignments): void
    {
        foreach ($assignments as $a) {
            $ur = $user->roleAssignments()->where('role_id', $a['role_id'])->firstOrFail();
            $ur->forceFill(['scope_override' => $a['scope'] === null ? null : RoleScopes::toDb($a['scope'])])->save();
            DB::table('user_role_scopes')->where('user_role_id', $ur->id)->delete();
            $tenant = $user->tenant_id;
            foreach (['legal_entity_ids' => 'legal_entity_id', 'site_ids' => 'site_id', 'org_unit_ids' => 'org_unit_id'] as $key => $column) {
                foreach (array_unique($a[$key]) as $id) {
                    DB::table('user_role_scopes')->insert(['tenant_id' => $tenant, 'user_role_id' => $ur->id, $column => $id]);
                }
            }
        }
    }

    private function issueInvitation(User $user, string $origin): void
    {
        $this->tokens->issue(
            $user,
            UserToken::PURPOSE_INVITE,
            (int) config('identity.invitation_ttl_hours') * 60,
            DomainEventTypes::USER_INVITED,
            $origin,
        );
    }

    /**
     * @param  array<int, string>  $roleIds
     * @return array<int, string>
     */
    private function validatedRoles(array $roleIds, EffectivePermissions $actor): array
    {
        $roleIds = array_values(array_unique($roleIds));
        $valid = array_filter($roleIds, fn (string $id) => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1);
        $roles = Role::query()->whereIn('id', $valid)->get();
        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages(['role_ids' => [(string) trans('identity.users.unknown_role')]]);
        }

        // RN-SEG-03: no se asigna un rol con permisos que quien asigna no tiene.
        if (! $actor->isCompanyAdmin()) {
            $granted = DB::table('role_permissions')->whereIn('role_id', $roleIds)->pluck('permission_code')->unique();
            $missing = $granted->filter(fn ($c) => ! $actor->has((string) $c))->values()->all();
            if ($missing !== []) {
                throw new BusinessRuleViolatedException('RN-SEG-03', (string) trans('identity.roles.cannot_grant', ['codes' => implode(', ', $missing)]));
            }
        }

        return $roleIds;
    }

    private function guardUnique(string $kind, string $identifier, ?string $exceptUserId = null): void
    {
        $exists = UserIdentity::query()
            ->where('kind', $kind)
            ->where('identifier_hash', BlindIndex::for($kind, $identifier))
            ->when($exceptUserId !== null, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->exists();

        if ($exists) {
            throw new AyniException('duplicate', 409, null, [], ['fields' => [$kind === 'username' ? 'login' : 'email']]);
        }
    }

    /**
     * RN-SEG-02: la organización no puede quedar sin un Administrador empresa activo.
     */
    private function guardNotLastAdmin(User $user): void
    {
        if (! $this->isActiveAdmin($user)) {
            return;
        }

        $others = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('r.system_role_code', 'admin_empresa')
            ->whereNull('r.deleted_at')
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->where('u.id', '!=', $user->id)
            ->exists();

        if (! $others) {
            throw new BusinessRuleViolatedException('RN-SEG-02', (string) trans('identity.users.last_admin'));
        }
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->status === 'active' && DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->where('r.system_role_code', 'admin_empresa')
            ->whereNull('r.deleted_at')
            ->exists();
    }

    /**
     * @param  array<int, string>  $roleIds
     */
    private function keepsAdminRole(array $roleIds): bool
    {
        return Role::query()->whereIn('id', $roleIds)->where('system_role_code', 'admin_empresa')->exists();
    }

    private function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof User ? $user->id : null;
    }
}
