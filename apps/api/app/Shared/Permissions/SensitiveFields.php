<?php

namespace App\Shared\Permissions;

use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEG-9 · Permisos por campo sensible y registro de accesos (reglas 6 y 7 de CLAUDE.md).
 *
 * - Sin el permiso, el campo no viaja en la respuesta (no se enmascara en el cliente).
 * - No se puede filtrar ni ordenar por un campo sensible sin su permiso (400 invalid_filter).
 * - Cada lectura de campos sensibles deja una fila en data_access_logs.
 *
 * Todo Resource de un esquema con x-sensitive-fields pasa por present()/presentMany().
 */
final class SensitiveFields
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function mapFor(string $schema): array
    {
        /** @var array<string, array{0: string, 1: string}> $map */
        $map = (array) config("sensitive_fields.{$schema}", []);

        return $map;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function present(string $schema, array $data, EffectivePermissions $permissions, string $channel = 'api', ?string $subjectPersonId = null): array
    {
        return $this->presentMany($schema, [$data], $permissions, $channel, $subjectPersonId === null ? [] : [$subjectPersonId])[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string|null>  $subjectPersonIds  por fila (opcional)
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(string $schema, array $rows, EffectivePermissions $permissions, string $channel = 'api', array $subjectPersonIds = []): array
    {
        $map = $this->mapFor($schema);
        if ($map === []) {
            return $rows;
        }

        $logs = [];
        foreach ($rows as $i => $row) {
            $read = [];
            $category = 'other';
            foreach ($map as $field => [$permission, $cat]) {
                if (! array_key_exists($field, $row)) {
                    continue;
                }
                if ($permissions->has($permission)) {
                    $read[] = $field;
                    $category = $cat;
                } else {
                    unset($row[$field]);
                }
            }
            $rows[$i] = $row;

            if ($read !== []) {
                $logs[] = [
                    'tenant_id' => TenancyScope::requireTenant(),
                    'user_id' => $this->userId(),
                    'access_kind' => $channel,
                    'data_category' => $category,
                    'subject_person_id' => $subjectPersonIds[$i] ?? null,
                    'entity_type' => $schema,
                    'entity_id' => isset($row['id']) && is_string($row['id']) && preg_match('/^[0-9a-f-]{36}$/i', $row['id']) === 1 ? $row['id'] : null,
                    'fields' => '{'.implode(',', $read).'}',
                    'ip' => request()->ip(),
                    'occurred_at' => now(),
                ];
            }
        }

        if ($logs !== []) {
            DB::table('data_access_logs')->insert($logs);
        }

        return $rows;
    }

    /**
     * Rechaza filtros u ordenamientos por campos sensibles sin permiso (no se filtra «a ciegas»).
     */
    public function guardQuery(string $schema, Request $request, EffectivePermissions $permissions): void
    {
        $map = $this->mapFor($schema);
        $used = [];

        $filter = $request->query('filter');
        if (is_array($filter)) {
            $used = array_merge($used, array_keys($filter));
        }
        $sort = $request->query('sort');
        if (is_string($sort) && $sort !== '') {
            $used = array_merge($used, array_map(fn (string $s) => ltrim(trim($s), '-'), explode(',', $sort)));
        }

        foreach ($used as $field) {
            if (isset($map[$field]) && ! $permissions->has($map[$field][0])) {
                throw new InvalidFilterException(message: (string) trans('errors.invalid_filter'), allowed: []);
            }
        }
    }

    private function userId(): ?string
    {
        $user = request()->user();

        return $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : null;
    }
}
