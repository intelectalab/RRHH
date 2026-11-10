<?php

namespace App\Modules\Identity\Infrastructure\Search;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Search\SearchSource;
use Illuminate\Http\Request;

final class UserSearchSource implements SearchSource
{
    public function type(): string
    {
        return 'user';
    }

    public function permission(): string
    {
        return 'identity.users.view';
    }

    public function search(string $term, Request $request, int $limit): array
    {
        return User::query()
            ->where('display_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('display_name')->limit($limit)->get(['id', 'display_name', 'status'])
            ->map(fn (User $u) => ['id' => $u->id, 'title' => $u->display_name, 'subtitle' => $u->status, 'href' => '/configuracion/usuarios?q='.rawurlencode($u->display_name)])
            ->all();
    }
}
