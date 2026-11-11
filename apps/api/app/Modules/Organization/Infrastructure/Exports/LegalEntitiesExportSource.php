<?php

namespace App\Modules\Organization\Infrastructure\Exports;

use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Exports\ExportSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\Request;

/**
 * SEG-12 · Listado de empresas exportable: alcance de empresa en la consulta (igual que
 * GET /legal-entities) y campos personalizados sensibles solo con su permiso.
 */
final class LegalEntitiesExportSource implements ExportSource
{
    public function __construct(
        private readonly DataScope $scope,
        private readonly CustomFieldValues $customFields,
    ) {}

    public function resource(): string
    {
        return 'legal_entities';
    }

    public function permission(): string
    {
        return 'org.legal_entities.view';
    }

    public function filters(): array
    {
        return ['q', 'status', 'legal_entity_id'];
    }

    public function build(array $filters, Request $request, EffectivePermissions $permissions, int $limit): array
    {
        $scoped = $request->duplicate(['filter' => ['legal_entity_id' => $filters['legal_entity_id'] ?? null]]);
        $scoped->setUserResolver($request->getUserResolver());
        $query = LegalEntity::query()->orderBy('legal_name')->orderBy('id');
        $this->scope->applyLegalEntity($query, $scoped, 'org.legal_entities.view', 'id');
        if (($filters['status'] ?? '') === 'active' || ($filters['status'] ?? '') === 'archived') {
            $query->where('status', $filters['status'] === 'active' ? 'active' : 'inactive');
        }
        if (($filters['q'] ?? '') !== '') {
            $q = addcslashes($filters['q'], '%_\\');
            $query->where(fn ($w) => $w->where('legal_name', 'ilike', '%'.$q.'%')->orWhere('tax_id', 'like', $q.'%'));
        }
        $entities = $query->limit($limit + 1)->get();
        $truncated = $entities->count() > $limit;
        $entities = $entities->take($limit);

        $defs = $this->customFields->definitions('legal_entity');
        $visible = $defs->filter(fn ($d) => ! $d->is_sensitive || ($d->visibility_permission_code !== null && $permissions->has((string) $d->visibility_permission_code)));
        $customKeys = $visible->pluck('key')->map(fn ($k) => (string) $k)->values()->all();
        $sensitive = $visible->filter(fn ($d) => (bool) $d->is_sensitive)->pluck('key')->map(fn ($k) => 'custom.'.$k)->values()->all();

        $rows = [];
        foreach ($entities as $e) {
            $custom = $customKeys === [] ? [] : $this->customFields->read('legal_entity', $e->id, $permissions, 'export');
            $row = [
                (string) $e->legal_name, (string) ($e->trade_name ?? ''), (string) $e->tax_id, (string) $e->country_code,
                (string) $e->currency, (string) $e->timezone, $e->status === 'active' ? 'active' : 'archived',
            ];
            foreach ($customKeys as $key) {
                $row[] = ExportRegistry::cell($custom[$key] ?? null);
            }
            $rows[] = $row;
        }

        return [
            'columns' => array_merge(['name', 'trade_name', 'tax_id', 'country', 'currency', 'timezone', 'status'], array_map(fn ($k) => 'custom.'.$k, $customKeys)),
            'rows' => $rows,
            'sensitive' => $sensitive,
            'truncated' => $truncated,
        ];
    }
}
