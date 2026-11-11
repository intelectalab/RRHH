<?php

namespace App\Modules\Identity\Infrastructure\Exports;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Exports\ExportSource;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Security\FieldCipher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEG-12 · Listado de usuarios exportable (mismas columnas y filtros que Configuración → Usuarios).
 */
final class UsersExportSource implements ExportSource
{
    public function resource(): string
    {
        return 'users';
    }

    public function permission(): string
    {
        return 'identity.users.view';
    }

    public function filters(): array
    {
        return ['q', 'status'];
    }

    public function build(array $filters, Request $request, EffectivePermissions $permissions, int $limit): array
    {
        $query = User::query()->orderBy('display_name')->orderBy('id');
        if (($filters['q'] ?? '') !== '') {
            $query->where('display_name', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        if (($filters['status'] ?? '') !== '') {
            $query->whereIn('status', explode(',', $filters['status']));
        }
        $users = $query->limit($limit + 1)->get();
        $truncated = $users->count() > $limit;
        $users = $users->take($limit);

        $ids = $users->pluck('id')->all();
        $roles = DB::table('user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->whereIn('a.user_id', $ids)->orderBy('r.name')->get(['a.user_id', 'r.name'])
            ->groupBy('user_id')->map(fn ($g) => $g->pluck('name')->implode(', '));
        $emails = DB::table('user_identities')->whereIn('user_id', $ids)->where('kind', 'email')->pluck('identifier_enc', 'user_id');

        $rows = [];
        foreach ($users as $u) {
            $email = $emails[$u->id] ?? null;
            $rows[] = [
                (string) $u->display_name,
                $email === null ? '' : FieldCipher::decrypt((string) $email),
                (string) $u->status,
                (string) ($roles[$u->id] ?? ''),
                $u->last_login_at?->toIso8601String() ?? '',
            ];
        }

        return ['columns' => ['display_name', 'email', 'status', 'roles', 'last_login_at'], 'rows' => $rows, 'sensitive' => [], 'truncated' => $truncated];
    }
}
