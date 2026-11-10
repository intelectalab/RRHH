<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Infrastructure\Models\CustomFieldDefinition;
use App\Shared\Audit\AuditLogger;
use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CFG-4 · Definición de campos personalizados por entidad. Eliminar los desactiva (borrado lógico,
 * aparecen en la papelera) y conserva los valores ya guardados.
 */
final class CustomFieldController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ModuleGate $modules,
        private readonly PermissionResolver $resolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = CustomFieldDefinition::query()->orderBy('entity_type')->orderBy('sort_order')->orderBy('created_at');
        $entity = $request->input('filter.entity', $request->query('entity'));
        if (is_string($entity) && $entity !== '') {
            $query->where('entity_type', $entity);
        }
        $entities = array_keys($this->entities());
        $query->whereIn('entity_type', $entities);

        return response()->json([
            'data' => $query->limit(500)->get()->map(fn (CustomFieldDefinition $d) => $this->present($d))->all(),
            'meta' => ['per_page' => 500, 'next_cursor' => null, 'entities' => $entities, 'field_permissions' => $this->fieldPermissions()],
        ]);
    }

    /**
     * Campos del formulario de una entidad para quien la ve (sin exigir permisos de configuración):
     * los sensibles solo si tiene su permiso por campo.
     */
    public function form(Request $request): JsonResponse
    {
        $entity = (string) $request->query('entity', '');
        $config = $this->entities()[$entity] ?? null;
        abort_if($config === null, 404);
        $perms = $request->attributes->get('effective_permissions');
        $perms = $perms instanceof EffectivePermissions ? $perms : $this->resolver->for((string) $request->user()?->getAuthIdentifier());
        abort_unless($perms->has($config['view']), 403);

        $rows = CustomFieldDefinition::query()->where('entity_type', $entity)->orderBy('sort_order')->orderBy('created_at')->get()
            ->filter(fn (CustomFieldDefinition $d) => ! $d->is_sensitive || ($d->visibility_permission_code !== null && $perms->has($d->visibility_permission_code)))
            ->map(fn (CustomFieldDefinition $d) => ['key' => $d->key, 'label' => $d->label, 'type' => $d->data_type, 'options' => $d->options, 'required' => $d->is_required, 'sensitive' => $d->is_sensitive, 'section' => $d->section])
            ->values()->all();

        return response()->json(['data' => ['entity' => $entity, 'fields' => $rows, 'can_edit' => $perms->has($config['edit'])]]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        if (CustomFieldDefinition::withTrashed()->where('entity_type', $data['entity'])->where('key', $data['key'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('settings.custom_field_key_taken'));
        }
        if (CustomFieldDefinition::query()->where('entity_type', $data['entity'])->count() >= (int) config('custom_fields.max_per_entity', 50)) {
            throw new AyniException('plan_limit_reached', 403, null, [], ['max' => (int) config('custom_fields.max_per_entity', 50)]);
        }
        $this->guardSensitive($data);

        $d = DB::transaction(function () use ($data): CustomFieldDefinition {
            $d = CustomFieldDefinition::create($this->attributes($data) + ['entity_type' => $data['entity'], 'key' => $data['key'], 'module_code' => $this->entities()[$data['entity']]['module'] ?? null]);
            $this->audit->record('custom_field.created', 'custom_field', $d->id, null, $this->present($d->refresh()));

            return $d;
        });

        return response()->json(['data' => $this->present($d)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $d = $this->find($id);
        $data = $request->validate($this->rules(false));
        $this->guardSensitive($data + ['sensitive' => $d->is_sensitive, 'permission' => $d->visibility_permission_code]);
        if (isset($data['type']) && $data['type'] !== $d->data_type && DB::table('custom_field_values')->where('custom_field_definition_id', $d->id)->exists()) {
            // Cambiar el tipo invalidaría los valores guardados.
            throw ValidationException::withMessages(['type' => [(string) trans('settings.custom_field_type_locked')]]);
        }

        DB::transaction(function () use ($d, $data): void {
            $before = $this->present($d);
            $d->forceFill($this->attributes($data))->save();
            $this->audit->record('custom_field.updated', 'custom_field', $d->id, $before, $this->present($d->refresh()));
        });

        return response()->json(['data' => $this->present($d)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $d = $this->find($id);
        DB::transaction(function () use ($d): void {
            $before = $this->present($d);
            $d->delete();
            $this->audit->record('custom_field.deleted', 'custom_field', $d->id, $before, null);
        });

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomFieldDefinition $d): array
    {
        return [
            'id' => $d->id,
            'entity' => $d->entity_type,
            'key' => $d->key,
            'label' => $d->label,
            'type' => $d->data_type,
            'options' => $d->options,
            'required' => $d->is_required,
            'sensitive' => $d->is_sensitive,
            'permission' => $d->visibility_permission_code,
            'section' => $d->section,
            'sort_order' => $d->sort_order,
            'values_count' => DB::table('custom_field_values')->where('custom_field_definition_id', $d->id)->count(),
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'entity' => [$creating ? 'required' : 'prohibited', Rule::in(array_keys($this->entities()))],
            'key' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'label' => [$req, 'string', 'max:120'],
            'type' => [$req, Rule::in(CustomFieldValues::TYPES)],
            'options' => ['sometimes', 'nullable', 'array', 'max:100'],
            'options.*' => ['string', 'max:120', 'distinct'],
            'required' => ['sometimes', 'boolean'],
            'sensitive' => ['sometimes', 'boolean'],
            'permission' => ['sometimes', 'nullable', 'string', Rule::in($this->fieldPermissions())],
            'section' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $map = ['label' => 'label', 'type' => 'data_type', 'options' => 'options', 'required' => 'is_required', 'sensitive' => 'is_sensitive', 'permission' => 'visibility_permission_code', 'section' => 'section', 'sort_order' => 'sort_order'];
        $out = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $out[$col] = $data[$in];
            }
        }
        if (in_array($data['type'] ?? null, ['select', 'multiselect'], true) && empty($data['options'])) {
            throw ValidationException::withMessages(['options' => [(string) trans('settings.custom_field_options_required')]]);
        }

        return $out;
    }

    /**
     * Un campo sensible exige un permiso por campo (regla 6).
     *
     * @param  array<string, mixed>  $data
     */
    private function guardSensitive(array $data): void
    {
        if (($data['sensitive'] ?? false) === true && empty($data['permission'])) {
            throw ValidationException::withMessages(['permission' => [(string) trans('settings.custom_field_permission_required')]]);
        }
    }

    /**
     * Entidades registradas cuyo módulo está contratado.
     *
     * @return array<string, array{module: string, view: string, edit: string}>
     */
    private function entities(): array
    {
        /** @var array<string, array{module: string, view: string, edit: string}> $all */
        $all = (array) config('custom_fields.entities', []);

        return array_filter($all, fn ($e) => $this->modules->isEnabled($e['module']));
    }

    /**
     * @return array<int, string>
     */
    private function fieldPermissions(): array
    {
        return DB::table('permissions')->where('kind', 'field')->orderBy('code')->pluck('code')->map(fn ($c) => (string) $c)->all();
    }

    private function find(string $id): CustomFieldDefinition
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var CustomFieldDefinition */
        return CustomFieldDefinition::query()->whereKey($id)->firstOrFail();
    }
}
