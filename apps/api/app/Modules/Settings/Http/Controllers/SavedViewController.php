<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Infrastructure\Models\SavedView;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UI-4 · Vistas guardadas de las tablas (filtros, columnas y orden).
 *
 * Cada usuario ve sus vistas y las compartidas de su organización; solo el dueño las cambia o
 * las borra. La vista guarda nombres de columna, nunca datos: al abrirla la tabla vuelve a
 * aplicar los permisos de quien la mira (una columna sin permiso no aparece).
 */
final class SavedViewController
{
    /** Vistas por usuario y tabla: evita que una lista sin control crezca sin fin. */
    private const MAX_PER_RESOURCE = 30;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->query('filter');
        $filters = is_array($filters) ? $filters : [];
        foreach (array_keys($filters) as $key) {
            if ($key !== 'resource') {
                throw new InvalidFilterException(message: (string) trans('settings.saved_view_filter'), allowed: ['resource']);
            }
        }
        $query = $this->visible($request)->orderBy('name');
        $resource = $filters['resource'] ?? $request->query('resource');
        if (is_string($resource) && $resource !== '') {
            $query->where('resource', $resource);
        }

        $rows = $query->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (SavedView $v) => $this->present($v, $request))->values()->all(),
            'meta' => ['per_page' => 200, 'total' => $rows->count(), 'next_cursor' => null],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $id), $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $owner = $this->userId($request);
        if (SavedView::query()->where('owner_user_id', $owner)->where('resource', $data['resource'])->count() >= self::MAX_PER_RESOURCE) {
            throw new AyniException('plan_limit_reached', 403, (string) trans('settings.saved_view_limit', ['max' => self::MAX_PER_RESOURCE]));
        }
        $this->guardName($owner, (string) $data['resource'], (string) $data['name']);

        $view = SavedView::create([
            'owner_user_id' => $owner,
            'resource' => $data['resource'],
            'name' => trim((string) $data['name']),
            'config' => $this->config($data),
            'is_shared' => (bool) ($data['shared'] ?? false),
        ]);

        return response()->json(['data' => $this->present($view, $request)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $view = $this->own($request, $id);
        $data = $request->validate($this->rules(false));
        if (isset($data['name']) && trim((string) $data['name']) !== $view->name) {
            $this->guardName($view->owner_user_id, $view->resource, (string) $data['name'], $view->id);
            $view->name = trim((string) $data['name']);
        }
        $view->config = $this->config($data, $view->config);
        if (array_key_exists('shared', $data)) {
            $view->is_shared = (bool) $data['shared'];
        }
        $view->save();

        return response()->json(['data' => $this->present($view->refresh(), $request)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->own($request, $id)->delete();

        return response()->json(null, 204);
    }

    /**
     * @return Builder<SavedView>
     */
    private function visible(Request $request): Builder
    {
        $user = $this->userId($request);

        return SavedView::query()->where(fn (Builder $w) => $w->where('owner_user_id', $user)->orWhere('is_shared', true));
    }

    private function find(Request $request, string $id): SavedView
    {
        $view = $this->visible($request)->whereKey($id)->first();
        abort_unless($view instanceof SavedView, 404);

        return $view;
    }

    /** Solo el dueño cambia o borra una vista; las compartidas de otros se ven pero no se tocan. */
    private function own(Request $request, string $id): SavedView
    {
        $view = $this->find($request, $id);
        if ($view->owner_user_id !== $this->userId($request)) {
            throw new AyniException('forbidden', 403, (string) trans('settings.saved_view_not_owner'));
        }

        return $view;
    }

    private function guardName(string $owner, string $resource, string $name, ?string $except = null): void
    {
        $taken = SavedView::query()->where('owner_user_id', $owner)->where('resource', $resource)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except))
            ->exists();
        if ($taken) {
            throw new AyniException('duplicate', 409, (string) trans('settings.saved_view_name_taken'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function config(array $data, array $current = []): array
    {
        return [
            'filters' => array_key_exists('filters', $data) ? (array) ($data['filters'] ?? []) : ($current['filters'] ?? []),
            'columns' => array_key_exists('columns', $data) ? array_values(array_map('strval', (array) ($data['columns'] ?? []))) : ($current['columns'] ?? []),
            'sort' => array_key_exists('sort', $data) ? (string) ($data['sort'] ?? '') : ($current['sort'] ?? ''),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'resource' => [$creating ? 'required' : 'prohibited', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'name' => [$req, 'string', 'min:1', 'max:80'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'filters.*' => ['nullable'],
            'columns' => ['sometimes', 'nullable', 'array', 'max:40'],
            'columns.*' => ['string', 'max:60'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:200'],
            'shared' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SavedView $v, Request $request): array
    {
        $config = $v->config;

        return [
            'id' => $v->id,
            'resource' => $v->resource,
            'name' => $v->name,
            'filters' => (object) ($config['filters'] ?? []),
            'columns' => array_values((array) ($config['columns'] ?? [])),
            'sort' => (string) ($config['sort'] ?? ''),
            'shared' => $v->is_shared,
            'mine' => $v->owner_user_id === $this->userId($request),
            'created_at' => $v->created_at?->toIso8601String(),
            'updated_at' => $v->updated_at?->toIso8601String(),
        ];
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
