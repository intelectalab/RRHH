<?php

namespace App\Shared\Query;

use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * UI-4 · Listados que alimentan la tabla de la interfaz: orden declarado y paginación en servidor.
 *
 * - `sort=campo,-otro`: solo campos declarados (400 invalid_filter si no); siempre desempata por id
 *   para que la paginación sea estable.
 * - Con `cursor` la paginación es por cursor (compatibilidad con PLT-11); sin cursor es por página y
 *   devuelve `total`, que la tabla usa para «N registros» y para confirmar acciones masivas.
 * - `filter[x]`: solo filtros declarados (cada listado los aplica).
 */
final class TableQuery
{
    /** @var array{entity: string, id_column: string, permissions: EffectivePermissions|null}|null */
    private ?array $custom = null;

    /**
     * @param  array<string, string>  $sorts  campo público => columna
     * @param  array<int, string>  $filters  filtros admitidos en filter[...]
     */
    public function __construct(
        private readonly array $sorts,
        private readonly array $filters = [],
    ) {}

    /**
     * CFG-5 · El listado admite además filter[custom.clave] de los campos personalizados de la entidad
     * que el usuario puede ver; se aplican aquí, sin código del módulo.
     */
    public function withCustomFields(string $entity, string $idColumn, ?EffectivePermissions $permissions): self
    {
        $copy = clone $this;
        $copy->custom = ['entity' => $entity, 'id_column' => $idColumn, 'permissions' => $permissions];

        return $copy;
    }

    /**
     * Valor de un filtro declarado: filter[x] o, por compatibilidad, ?x=.
     */
    public static function filter(Request $request, string $name): ?string
    {
        $filters = $request->query('filter');
        $value = is_array($filters) && array_key_exists($name, $filters) ? $filters[$name] : $request->query($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{ids: array<int, string>, meta: array<string, mixed>}
     */
    public function page(Builder $query, Request $request, string $defaultSort): array
    {
        $filters = $this->filters;
        if ($this->custom !== null) {
            $fields = app(CustomFieldValues::class);
            $filters = array_merge($filters, $fields->filterKeys($this->custom['entity'], $this->custom['permissions']));
            (new QuerySpecification($filters, array_keys($this->sorts)))->validate($request);
            $fields->applyFilters($query, $this->custom['entity'], $this->custom['id_column'], $request, $this->custom['permissions']);
        } else {
            (new QuerySpecification($filters, array_keys($this->sorts)))->validate($request);
        }
        $params = PaginationParams::fromRequest($request);

        if ($params->cursor !== null) {
            $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, $this->sorts[$defaultSort] ?? 'created_at');

            return [
                'ids' => array_map('strval', array_column($page['data'], 'id')),
                'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
            ];
        }

        // El total se cuenta antes de ordenar (PostgreSQL no admite ORDER BY en un count sin agrupar).
        $total = (int) (clone $query)->count();
        $perPage = min(max(1, $params->perPage), PaginationParams::MAX_PER_PAGE);

        $sort = $request->query('sort');
        $sort = is_string($sort) && trim($sort) !== '' ? trim($sort) : $defaultSort;
        foreach (explode(',', $sort) as $field) {
            $field = trim($field);
            $desc = str_starts_with($field, '-');
            $column = $this->sorts[ltrim($field, '-+')] ?? null;
            if ($column !== null) {
                // Los vacíos van al final en ambos sentidos: la tabla no empieza con filas sin dato.
                $query->orderByRaw(sprintf('%s %s NULLS LAST', $query->getModel()->qualifyColumn($column), $desc ? 'DESC' : 'ASC'));
            }
        }
        $query->orderBy($query->getModel()->qualifyColumn('id'));

        $ids = $query->toBase()->select($query->getModel()->qualifyColumn('id'))
            ->offset(($params->page - 1) * $perPage)->limit($perPage)->pluck('id')
            ->map(fn ($id) => (string) $id)->values()->all();

        return [
            'ids' => $ids,
            'meta' => ['page' => $params->page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null],
        ];
    }
}
