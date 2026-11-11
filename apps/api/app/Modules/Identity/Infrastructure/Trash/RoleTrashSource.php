<?php

namespace App\Modules\Identity\Infrastructure\Trash;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Trash\PurgesSafely;
use App\Shared\Trash\TrashSource;
use Illuminate\Support\Facades\DB;

/**
 * Roles personalizados eliminados: se restauran con sus permisos y alcances (no se borraron).
 */
final class RoleTrashSource implements TrashSource
{
    use PurgesSafely;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function type(): string
    {
        return 'role';
    }

    public function permission(): string
    {
        return 'identity.roles.view';
    }

    public function items(?string $search, int $limit): array
    {
        $q = Role::onlyTrashed()->orderByDesc('deleted_at');
        if ($search !== null && $search !== '') {
            $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        return $q->limit($limit)->get()->map(fn (Role $r) => [
            'id' => $r->id,
            'title' => (string) $r->name,
            'subtitle' => DB::table('role_permissions')->where('role_id', $r->id)->count().' permisos',
            'deleted_at' => (string) $r->deleted_at?->toIso8601String(),
        ])->all();
    }

    public function owns(string $id): bool
    {
        return Role::onlyTrashed()->whereKey($id)->exists();
    }

    public function restore(string $id): void
    {
        /** @var Role $role */
        $role = Role::onlyTrashed()->whereKey($id)->firstOrFail();
        if (Role::query()->where('name', $role->name)->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('identity.roles.name_taken'));
        }
        DB::transaction(function () use ($role): void {
            $role->restore();
            $this->audit->record('role.restored', 'role', $role->id, null, ['name' => $role->name]);
        });
        $this->permissions->forget();
    }

    public function expired(\DateTimeInterface $deletedBefore): array
    {
        return Role::onlyTrashed()->where('deleted_at', '<', $deletedBefore)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * Se borran sus permisos con el rol. Si alguien aún lo tiene asignado o un flujo lo usa, se conserva.
     */
    public function purge(string $id): bool
    {
        /** @var Role|null $role */
        $role = Role::onlyTrashed()->whereKey($id)->first();
        if ($role === null) {
            return true;
        }

        return $this->purgeSafely(function () use ($role): void {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            $role->forceDelete();
            $this->audit->record('role.purged', 'role', $role->id, ['name' => $role->name], null);
        }, fn () => $this->permissions->forget());
    }
}
