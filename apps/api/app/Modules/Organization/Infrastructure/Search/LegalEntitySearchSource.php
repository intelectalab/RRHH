<?php

namespace App\Modules\Organization\Infrastructure\Search;

use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Permissions\DataScope;
use App\Shared\Search\SearchSource;
use Illuminate\Http\Request;

/** Empresas dentro del alcance del usuario (por nombre o RUC). */
final class LegalEntitySearchSource implements SearchSource
{
    public function __construct(private readonly DataScope $scope) {}

    public function type(): string
    {
        return 'legal_entity';
    }

    public function permission(): string
    {
        return 'org.legal_entities.view';
    }

    public function search(string $term, Request $request, int $limit): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query = LegalEntity::query()->where(fn ($q) => $q->where('legal_name', 'ilike', $like)->orWhere('tax_id', 'like', $like));
        $user = $request->user();
        if ($user !== null) {
            $allowed = $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $this->permission());
            if ($allowed !== null) {
                $query->whereIn('id', $allowed);
            }
        }

        return $query->orderBy('legal_name')->limit($limit)->get(['id', 'legal_name', 'tax_id'])
            ->map(fn (LegalEntity $e) => ['id' => $e->id, 'title' => $e->legal_name, 'subtitle' => $e->tax_id, 'href' => '/organizacion/empresas'])
            ->all();
    }
}
