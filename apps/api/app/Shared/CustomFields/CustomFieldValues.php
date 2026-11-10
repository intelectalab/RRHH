<?php

namespace App\Shared\CustomFields;

use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
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
     *
     * @return array<string, mixed>
     */
    public function read(string $entity, string $entityId, ?EffectivePermissions $permissions): array
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
                'tenant_id' => TenancyScope::requireTenant(), 'user_id' => request()->user()?->getAuthIdentifier(), 'access_kind' => 'api', 'data_category' => 'other',
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
