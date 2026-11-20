<?php

namespace App\Shared\CustomFields;

use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CFG-4 · Valores tipados de campos personalizados para cualquier entidad registrada. Ningún módulo
 * programa por separado sus campos: lee y guarda por aquí. Un campo sensible exige su permiso por
 * campo para viajar en la respuesta (regla 6) y cada lectura queda registrada (regla 7).
 */
final class CustomFieldValues
{
    public const TYPES = ['text', 'number', 'date', 'select', 'multiselect', 'checkbox', 'user', 'file', 'url', 'currency', 'percent'];

    /**
     * Definiciones activas de una entidad, en orden.
     *
     * @return Collection<int, \stdClass>
     */
    public function definitions(string $entity): Collection
    {
        /** @var Collection<int, \stdClass> */
        return DB::table('custom_field_definitions')->where('entity_type', $entity)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('created_at')->get();
    }

    /**
     * Valores visibles para quien consulta: {key: valor}. Los sensibles sin permiso no viajan.
     * $channel es el canal del registro de accesos (api, export…).
     *
     * @return array<string, mixed>
     */
    public function read(string $entity, string $entityId, ?EffectivePermissions $permissions, string $channel = 'api'): array
    {
        $defs = $this->definitions($entity)->keyBy('id');
        if ($defs->isEmpty()) {
            return [];
        }
        $rows = DB::table('custom_field_values')->where('entity_type', $entity)->where('entity_id', $entityId)->whereIn('custom_field_definition_id', $defs->keys())->get();
        $out = [];
        $sensitiveRead = [];
        foreach ($defs as $d) {
            if ($d->is_sensitive && ($permissions === null || $d->visibility_permission_code === null || ! $permissions->has((string) $d->visibility_permission_code))) {
                continue;
            }
            $row = $rows->firstWhere('custom_field_definition_id', $d->id);
            $out[(string) $d->key] = $row === null ? null : json_decode((string) $row->value, true);
            if ($d->is_sensitive && $row !== null) {
                $sensitiveRead[] = (string) $d->key;
            }
        }
        if ($sensitiveRead !== []) {
            DB::table('data_access_logs')->insert([
                'tenant_id' => TenancyScope::requireTenant(), 'user_id' => request()->user()?->getAuthIdentifier(), 'access_kind' => $channel, 'data_category' => 'other',
                'entity_type' => $entity, 'entity_id' => $entityId, 'fields' => '{'.implode(',', array_map(fn ($k) => 'custom.'.$k, $sensitiveRead)).'}', 'ip' => request()->ip(),
                'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        }

        return $out;
    }

    /**
     * Valida los valores recibidos; con $creating exige los obligatorios.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> valores normalizados por clave
     */
    public function validate(string $entity, array $input, bool $creating, ?EffectivePermissions $permissions): array
    {
        $defs = $this->definitions($entity)->keyBy('key');
        $errors = [];
        $clean = [];
        foreach ($input as $key => $value) {
            $d = $defs->get($key);
            if ($d === null) {
                $errors["custom_fields.{$key}"][] = (string) trans('settings.custom_field_unknown');

                continue;
            }
            if ($d->is_sensitive && ($permissions === null || $d->visibility_permission_code === null || ! $permissions->has((string) $d->visibility_permission_code))) {
                $errors["custom_fields.{$key}"][] = (string) trans('settings.custom_field_forbidden');

                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                if ($d->is_required) {
                    $errors["custom_fields.{$key}"][] = (string) trans('settings.custom_field_required');
                }
                $clean[$key] = null;

                continue;
            }
            $normalized = $this->normalize($d, $value);
            if ($normalized === null) {
                $errors["custom_fields.{$key}"][] = (string) trans('settings.invalid_value');
            } else {
                $clean[$key] = $normalized;
            }
        }
        if ($creating) {
            foreach ($defs as $key => $d) {
                if ($d->is_required && ! array_key_exists((string) $key, $input)) {
                    $errors["custom_fields.{$key}"][] = (string) trans('settings.custom_field_required');
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Guarda valores ya validados (null borra el valor de ese campo para esa entidad).
     *
     * @param  array<string, mixed>  $clean
     */
    public function save(string $entity, string $entityId, array $clean): void
    {
        $defs = $this->definitions($entity)->keyBy('key');
        foreach ($clean as $key => $value) {
            $d = $defs->get($key);
            if ($d === null) {
                continue;
            }
            $where = ['custom_field_definition_id' => $d->id, 'entity_id' => $entityId];
            if ($value === null) {
                DB::table('custom_field_values')->where($where)->delete();

                continue;
            }
            $exists = DB::table('custom_field_values')->where($where)->exists();
            if ($exists) {
                DB::table('custom_field_values')->where($where)->update(['value' => json_encode($value), 'updated_at' => now()]);
            } else {
                DB::table('custom_field_values')->insert($where + ['tenant_id' => TenancyScope::requireTenant(), 'entity_type' => $entity, 'value' => json_encode($value)]);
            }
        }
    }

    /**
     * CFG-5 · Claves de filtro (custom.clave) que quien consulta puede usar en esta entidad.
     *
     * @return list<string>
     */
    public function filterKeys(string $entity, ?EffectivePermissions $permissions): array
    {
        return array_values(array_map(fn ($d) => 'custom.'.$d->key, $this->visible($entity, $permissions)->all()));
    }

    /**
     * CFG-5 · Definiciones que quien consulta puede ver (los sensibles solo con su permiso por campo).
     *
     * @return Collection<int, \stdClass>
     */
    public function visible(string $entity, ?EffectivePermissions $permissions): Collection
    {
        return $this->definitions($entity)->filter(fn ($d) => ! $d->is_sensitive
            || ($permissions !== null && $d->visibility_permission_code !== null && $permissions->has((string) $d->visibility_permission_code)))->values();
    }

    /**
     * CFG-5 · Aplica filter[custom.clave] a cualquier listado: un campo personalizado se filtra igual que
     * uno nativo, sin que el módulo lo programe. Texto y URL buscan por contenido; números, porcentajes,
     * montos y fechas admiten rango «desde..hasta» (un extremo puede faltar); el resto compara igualdad
     * (multiselección: contiene la opción). Un campo que el usuario no puede ver responde 400.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  EloquentBuilder<TModel>|QueryBuilder  $query
     */
    public function applyFilters(EloquentBuilder|QueryBuilder $query, string $entity, string $idColumn, Request $request, ?EffectivePermissions $permissions): void
    {
        $filters = $request->query('filter');
        if (is_array($filters)) {
            $this->applyFilterValues($query, $entity, $idColumn, $filters, $permissions);
        }
    }

    /**
     * Igual que applyFilters, con los valores ya leídos ({custom.clave: valor}); lo usan las exportaciones.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  EloquentBuilder<TModel>|QueryBuilder  $query
     * @param  array<mixed>  $filters
     */
    public function applyFilterValues(EloquentBuilder|QueryBuilder $query, string $entity, string $idColumn, array $filters, ?EffectivePermissions $permissions): void
    {
        $defs = null;
        foreach ($filters as $name => $raw) {
            $name = (string) $name;
            if (! str_starts_with($name, 'custom.')) {
                continue;
            }
            $defs ??= $this->visible($entity, $permissions)->keyBy('key');
            $d = $defs->get(substr($name, 7));
            $value = is_string($raw) ? trim($raw) : null;
            if ($d === null || $value === null || $value === '') {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), $this->filterKeys($entity, $permissions));
            }
            $query->whereExists(function ($sub) use ($d, $idColumn, $value, $entity, $permissions): void {
                $sub->selectRaw('1')->from('custom_field_values as cfv')
                    ->where('cfv.custom_field_definition_id', $d->id)->whereColumn('cfv.entity_id', $idColumn);
                $this->condition($sub, $d, $value, $entity, $permissions);
            });
        }
    }

    /**
     * CFG-5 · Valores de varios registros de una vez (columnas de tablas y exportaciones), sin N+1.
     * Los sensibles leídos quedan en el registro de accesos (regla 7), una fila por registro.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>> id => {clave: valor}
     */
    public function readMany(string $entity, array $ids, ?EffectivePermissions $permissions, string $channel = 'api'): array
    {
        $defs = $this->visible($entity, $permissions)->keyBy('id');
        if ($defs->isEmpty() || $ids === []) {
            return [];
        }
        $out = array_fill_keys($ids, []);
        foreach ($ids as $id) {
            foreach ($defs as $d) {
                $out[$id][(string) $d->key] = null;
            }
        }
        $sensitive = [];
        $rows = DB::table('custom_field_values')->where('entity_type', $entity)->whereIn('entity_id', $ids)->whereIn('custom_field_definition_id', $defs->keys())->get();
        foreach ($rows as $row) {
            $d = $defs->get($row->custom_field_definition_id);
            if ($d === null) {
                continue;
            }
            $out[(string) $row->entity_id][(string) $d->key] = json_decode((string) $row->value, true);
            if ($d->is_sensitive) {
                $sensitive[(string) $row->entity_id][] = 'custom.'.$d->key;
            }
        }
        foreach ($sensitive as $entityId => $fields) {
            DB::table('data_access_logs')->insert([
                'tenant_id' => TenancyScope::requireTenant(), 'user_id' => request()->user()?->getAuthIdentifier(), 'access_kind' => $channel, 'data_category' => 'other',
                'entity_type' => $entity, 'entity_id' => $entityId, 'fields' => '{'.implode(',', $fields).'}', 'ip' => request()->ip(),
                'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        }

        return $out;
    }

    /**
     * CFG-5 · Interpreta el texto de una celda importada según el tipo del campo (antes de validar).
     */
    public static function fromText(string $type, string $text): mixed
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        return match ($type) {
            'checkbox' => match (mb_strtolower($text)) {
                'sí', 'si', 'yes', 'sim', 'true', '1', 'x' => true,
                'no', 'não', 'nao', 'false', '0' => false,
                default => $text,
            },
            'multiselect' => array_values(array_filter(array_map('trim', preg_split('/[;|]/', $text) ?: []), fn ($v) => $v !== '')),
            'number', 'percent' => str_replace(',', '.', $text),
            'date' => preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $text, $m) === 1 ? "{$m[3]}-{$m[2]}-{$m[1]}" : $text,
            'currency' => preg_match('/^([A-Z]{3})\s*(-?[\d.,]+)$|^(-?[\d.,]+)\s*([A-Z]{3})$/', strtoupper($text), $m) === 1
                ? ['currency' => $m[1] !== '' ? $m[1] : $m[4], 'amount' => str_replace(',', '.', $m[2] !== '' ? $m[2] : $m[3])]
                : $text,
            default => $text,
        };
    }

    private function condition(QueryBuilder $sub, \stdClass $d, string $value, string $entity, ?EffectivePermissions $permissions): void
    {
        $type = (string) $d->data_type;
        $invalid = fn () => new InvalidFilterException((string) trans('errors.invalid_filter'), $this->filterKeys($entity, $permissions));
        if (in_array($type, ['number', 'percent', 'currency', 'date'], true)) {
            [$from, $to] = str_contains($value, '..') ? array_map('trim', explode('..', $value, 2)) : [$value, $value];
            $pattern = $type === 'date' ? '/^\d{4}-\d{2}-\d{2}$/' : '/^-?\d{1,15}(\.\d{1,6})?$/';
            foreach ([$from, $to] as $edge) {
                if ($edge !== '' && preg_match($pattern, $edge) !== 1) {
                    throw $invalid();
                }
            }
            $expr = match ($type) {
                'date' => "(cfv.value #>> '{}')::date",
                'currency' => "(cfv.value ->> 'amount')::numeric",
                default => "(cfv.value #>> '{}')::numeric",
            };
            if ($from !== '') {
                $sub->whereRaw("{$expr} >= ?", [$from]);
            }
            if ($to !== '') {
                $sub->whereRaw("{$expr} <= ?", [$to]);
            }

            return;
        }
        match ($type) {
            'text', 'url' => $sub->whereRaw("(cfv.value #>> '{}') ILIKE ?", ['%'.addcslashes($value, '%_\\').'%']),
            'multiselect' => $sub->whereRaw('cfv.value @> ?::jsonb', [(string) json_encode([$value])]),
            'checkbox' => in_array($value, ['true', 'false'], true)
                ? $sub->whereRaw("(cfv.value #>> '{}') = ?", [$value])
                : throw $invalid(),
            default => $sub->whereRaw("(cfv.value #>> '{}') = ?", [$value]),
        };
    }

    private function normalize(\stdClass $d, mixed $v): mixed
    {
        $options = $d->options === null ? [] : (array) json_decode((string) $d->options, true);

        return match ((string) $d->data_type) {
            'text' => is_string($v) && mb_strlen($v) <= 2000 ? $v : null,
            'number' => (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $v) === 1)) ? (string) $v : null,
            'percent' => (is_int($v) || (is_string($v) && preg_match('/^\d{1,3}(\.\d{1,4})?$/', $v) === 1)) && (float) $v <= 100 ? (string) $v : null,
            'date' => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null,
            'checkbox' => is_bool($v) ? $v : null,
            'select' => is_string($v) && in_array($v, $options, true) ? $v : null,
            'multiselect' => is_array($v) && array_is_list($v) && array_diff($v, $options) === [] ? array_values(array_unique($v)) : null,
            'url' => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $v) === 1 ? $v : null,
            'user' => is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v) === 1 && DB::table('users')->where('id', $v)->whereNull('deleted_at')->exists() ? $v : null,
            'file' => is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v) === 1 && DB::table('stored_files')->where('id', $v)->exists() ? $v : null,
            // Regla 12: monto con moneda, decimal exacto en texto.
            'currency' => is_array($v) && isset($v['amount'], $v['currency']) && is_string($v['amount']) && preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $v['amount']) === 1
                && DB::table('currencies')->where('code', $v['currency'])->exists() ? ['amount' => $v['amount'], 'currency' => (string) $v['currency']] : null,
            default => null,
        };
    }
}
