<?php

namespace App\Modules\Identity\Infrastructure\Search;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Shared\Search\SearchSource;
use Illuminate\Http\Request;

final class RoleSearchSource implements SearchSource
{
    public function type(): string
    {
        return 'role';
    }

    public function permission(): string
    {
        return 'identity.roles.view';
    }

    public function search(string $term, Request $request, int $limit): array
    {
        return Role::query()
            ->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'is_system'])
            ->map(fn (Role $r) => ['id' => $r->id, 'title' => $r->name, 'subtitle' => null, 'href' => '/configuracion/roles?id='.$r->id])
            ->all();
    }
}
