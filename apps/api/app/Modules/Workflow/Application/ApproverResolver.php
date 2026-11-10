<?php

namespace App\Modules\Workflow\Application;

use Illuminate\Support\Facades\DB;

/**
 * WFL-3 · Resuelve aprobadores concretos: usuario fijo, rol, jefe directo o jefe del jefe,
 * y aplica delegaciones vigentes. El jefe sale de la asignación vigente (jefe por posición
 * con excepción por relación), igual que el alcance «equipo».
 */
final class ApproverResolver
{
    /**
     * @param  array<string, mixed>  $approver  {kind: user|role|manager|manager_of_manager, user_id?, role_id?}
     * @return array{user_id: string|null, role_id: string|null}|null null = no se pudo resolver
     */
    public function resolve(array $approver, string $requesterUserId): ?array
    {
        return match ($approver['kind'] ?? null) {
            'user' => isset($approver['user_id']) ? ['user_id' => (string) $approver['user_id'], 'role_id' => null] : null,
            'role' => isset($approver['role_id']) ? ['user_id' => null, 'role_id' => (string) $approver['role_id']] : null,
            'manager' => $this->asUser($this->managerOf($requesterUserId)),
            'manager_of_manager' => $this->asUser(($m = $this->managerOf($requesterUserId)) === null ? null : $this->managerOf($m)),
            default => null,
        };
    }

    /**
     * RN-WFL-07: si el aprobador tiene una delegación vigente, la tarea va al delegado.
     *
     * @return array{user_id: string, delegated_from: string|null}
     */
    public function applyDelegation(string $userId, string $subjectType): array
    {
        $today = now()->toDateString();
        $to = DB::table('approval_delegations')
            ->where('from_user_id', $userId)
            ->whereNull('revoked_at')
            ->where('starts_on', '<=', $today)
            ->where('ends_on', '>=', $today)
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', $subjectType))
            ->orderByDesc('starts_on')
            ->value('to_user_id');

        return $to === null ? ['user_id' => $userId, 'delegated_from' => null] : ['user_id' => (string) $to, 'delegated_from' => $userId];
    }

    public function isActiveUser(string $userId): bool
    {
        return DB::table('users')->where('id', $userId)->where('status', 'active')->whereNull('deleted_at')->exists();
    }

    /**
     * Jefe (usuario) de un usuario según su relación laboral vigente.
     */
    public function managerOf(string $userId): ?string
    {
        $today = now()->toDateString();
        $row = DB::table('users as u')
            ->join('employments as e', 'e.person_id', '=', 'u.person_id')
            ->join('employment_assignments as a', function ($j) use ($today): void {
                $j->on('a.employment_id', '=', 'e.id')->where('a.valid_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today));
            })
            ->leftJoin('positions as p', 'p.id', '=', 'a.position_id')
            ->where('u.id', $userId)
            ->whereIn('e.status', ['active', 'suspended', 'terminating'])
            ->first(['a.manager_employment_id', 'p.reports_to_position_id']);

        if ($row === null) {
            return null;
        }

        if ($row->manager_employment_id !== null) {
            $managerPerson = DB::table('employments')->where('id', $row->manager_employment_id)->value('person_id');
        } elseif ($row->reports_to_position_id !== null) {
            $managerPerson = DB::table('employment_assignments as a')
                ->join('employments as e', 'e.id', '=', 'a.employment_id')
                ->where('a.position_id', $row->reports_to_position_id)
                ->where('a.valid_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today))
                ->value('e.person_id');
        } else {
            return null;
        }

        if ($managerPerson === null) {
            return null;
        }

        $manager = DB::table('users')->where('person_id', $managerPerson)->whereNull('deleted_at')->value('id');

        return $manager === null ? null : (string) $manager;
    }

    /**
     * @return array{user_id: string|null, role_id: string|null}|null
     */
    private function asUser(?string $userId): ?array
    {
        return $userId === null ? null : ['user_id' => $userId, 'role_id' => null];
    }

    public function fallbackRoleId(): ?string
    {
        $id = DB::table('roles')->where('system_role_code', 'rrhh')->whereNull('deleted_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    public function userHasRole(string $userId, string $roleId): bool
    {
        $today = now()->toDateString();

        return DB::table('user_roles')->where('user_id', $userId)->where('role_id', $roleId)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->exists();
    }
}
