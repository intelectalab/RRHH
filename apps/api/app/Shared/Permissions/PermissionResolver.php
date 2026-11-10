<?php

namespace App\Shared\Permissions;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * Calcula los permisos efectivos de un usuario (SEG-8). Se consulta en cada
 * petición (memoizado solo dentro de ella), de modo que un cambio en un rol se
 * aplica de inmediato.
 */
final class PermissionResolver
{
    /** @var array<string, EffectivePermissions> */
    private array $memo = [];

    public function for(string $userId): EffectivePermissions
    {
        $key = TenancyScope::requireTenant().'|'.$userId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $today = now()->toDateString();

        $rows = DB::table('user_roles as ur')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'ur.role_id')->on('r.tenant_id', '=', 'ur.tenant_id');
            })
            ->join('role_permissions as rp', function ($join): void {
                $join->on('rp.role_id', '=', 'r.id')->on('rp.tenant_id', '=', 'r.tenant_id');
            })
            ->join('permissions as p', 'p.code', '=', 'rp.permission_code')
            ->where('ur.user_id', $userId)
            ->whereNull('r.deleted_at')
            ->where(fn ($q) => $q->whereNull('ur.valid_from')->orWhere('ur.valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ur.valid_to')->orWhere('ur.valid_to', '>=', $today))
            ->get(['rp.permission_code', 'p.kind', DB::raw('COALESCE(ur.scope_override, rp.scope) as scope')]);

        $actions = [];
        $fields = [];
        foreach ($rows as $row) {
            $code = (string) $row->permission_code;
            $scope = (string) $row->scope;
            if ($row->kind === 'field') {
                $fields[$code] = isset($fields[$code]) ? EffectivePermissions::widest($fields[$code], $scope) : $scope;
            } else {
                $actions[$code] = isset($actions[$code]) ? EffectivePermissions::widest($actions[$code], $scope) : $scope;
            }
        }

        $systemRoles = DB::table('user_roles as ur')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'ur.role_id')->on('r.tenant_id', '=', 'ur.tenant_id');
            })
            ->where('ur.user_id', $userId)
            ->whereNull('r.deleted_at')
            ->whereNotNull('r.system_role_code')
            ->where(fn ($q) => $q->whereNull('ur.valid_from')->orWhere('ur.valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ur.valid_to')->orWhere('ur.valid_to', '>=', $today))
            ->pluck('r.system_role_code')
            ->map(fn ($c) => (string) $c)
            ->unique()->values()->all();

        ksort($actions);
        ksort($fields);

        return $this->memo[$key] = new EffectivePermissions($actions, $fields, $systemRoles);
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
