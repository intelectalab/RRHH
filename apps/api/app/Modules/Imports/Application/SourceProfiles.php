<?php

namespace App\Modules\Imports\Application;

use App\Modules\Imports\Infrastructure\Models\ImportTemplate;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Imports\ImportValues;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IMP-7 · Perfiles de origen: traducen la exportación de otro sistema (otra suite de RRHH, una
 * planilla tradicional, una hoja con estructura de T-Registro) a las columnas de los importadores de
 * Core HR, con equivalencias de códigos y valores por defecto.
 *
 * - Los perfiles del país vienen en packages/country-xx/data/perfiles_importacion.php («por validar»).
 * - La organización corrige un perfil o agrega uno nuevo como configuración: cada cambio es una
 *   versión nueva (fila nueva en import_templates con source_code); las anteriores no se tocan y la
 *   importación guarda la versión con que se tradujo.
 * - La importación sigue por el mismo motor (IMP-1): mismos validadores, vista previa y deshacer.
 */
final class SourceProfiles
{
    /** Cabeceras del perfil que deben estar en el archivo para proponerlo (proporción de sus columnas). */
    private const DETECT_THRESHOLD = 0.6;

    public function __construct(
        private readonly ImportRegistry $importers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Perfiles vigentes (la última versión de cada código), opcionalmente de un tipo de datos.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $entity = null): array
    {
        $out = [];
        foreach ($this->codes() as $code) {
            foreach ($this->entitiesOf($code) as $e) {
                if ($entity !== null && $e !== $entity) {
                    continue;
                }
                $profile = $this->find($code, $e);
                if ($profile !== null) {
                    $out[] = $profile;
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['entity'], $a['name']] <=> [$b['entity'], $b['name']]);

        return $out;
    }

    /**
     * Última versión de un perfil para un tipo de datos: la de la organización si la corrigió; si no,
     * la del paquete del país.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code, string $entity): ?array
    {
        $own = ImportTemplate::query()->where('source_code', $code)->where('entity', $entity)->orderByDesc('version')->first();
        $shipped = $this->shipped()[$code] ?? null;
        $shippedEntity = $shipped['entidades'][$entity] ?? null;
        if ($own !== null) {
            return $this->fromTemplate($own, $shipped !== null);
        }
        if ($shipped === null || ! is_array($shippedEntity)) {
            return null;
        }

        return [
            'code' => $code, 'entity' => $entity, 'name' => (string) $shipped['nombre'],
            'description' => $shipped['descripcion'] ?? null, 'version' => (int) $shipped['version'],
            'origin' => 'country_pack', 'to_validate' => (bool) ($shipped['por_validar'] ?? true),
            'columns' => self::columnsOf($shippedEntity['columnas'] ?? []),
            'value_maps' => self::mapsOf($shippedEntity['valores'] ?? []),
            'defaults' => self::defaultsOf($shippedEntity['por_defecto'] ?? []),
            'template_id' => null,
        ];
    }

    /**
     * Historia de versiones de un perfil (la del paquete y las que agregó la organización).
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $code, string $entity): array
    {
        $out = [];
        $shipped = $this->shipped()[$code] ?? null;
        if ($shipped !== null && isset($shipped['entidades'][$entity])) {
            $out[] = ['version' => (int) $shipped['version'], 'origin' => 'country_pack', 'created_at' => null, 'created_by_user_id' => null];
        }
        foreach (ImportTemplate::query()->where('source_code', $code)->where('entity', $entity)->orderBy('version')->get() as $row) {
            $out[] = ['version' => (int) $row->version, 'origin' => 'organization', 'created_at' => $row->getAttribute('created_at')?->toIso8601String(), 'created_by_user_id' => $row->created_by_user_id];
        }

        return $out;
    }

    /**
     * Guarda una versión nueva de un perfil (corrección o perfil propio) sin tocar las anteriores.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input, string $userId, string $locale): array
    {
        $entity = is_string($input['entity'] ?? null) ? $input['entity'] : '';
        $importer = $this->importers->get($entity);
        $errors = [];
        if ($importer === null) {
            $errors['entity'][] = (string) trans('imports.entity_invalid', [], $locale);
        }
        $code = is_string($input['code'] ?? null) ? mb_strtolower(trim($input['code'])) : '';
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{1,59}$/', $code) !== 1) {
            $errors['code'][] = (string) trans('imports.profiles.code_invalid', [], $locale);
        }
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            $errors['name'][] = (string) trans('imports.errors.required', ['column' => 'name'], $locale);
        }
        $keys = $importer === null ? [] : array_map(fn ($c) => $c->key, $importer->columns());
        $columns = self::columnsOf(is_array($input['columns'] ?? null) ? $input['columns'] : []);
        $maps = self::mapsOf(is_array($input['value_maps'] ?? null) ? $input['value_maps'] : []);
        $defaults = self::defaultsOf(is_array($input['defaults'] ?? null) ? $input['defaults'] : []);
        foreach (['columns' => $columns, 'value_maps' => $maps, 'defaults' => $defaults] as $field => $set) {
            foreach (array_keys($set) as $key) {
                if ($importer !== null && ! in_array($key, $keys, true) && ! str_starts_with($key, 'custom.')) {
                    $errors[$field.'.'.$key][] = (string) trans('imports.mapping.unknown_column', ['column' => $key], $locale);
                }
            }
        }
        if ($columns === [] && $importer !== null) {
            $errors['columns'][] = (string) trans('imports.profiles.columns_required', [], $locale);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($code, $entity, $name, $input, $columns, $maps, $defaults, $userId): array {
            $previous = $this->find($code, $entity);
            $version = max((int) ($previous['version'] ?? 0), (int) ImportTemplate::query()->where('source_code', $code)->where('entity', $entity)->max('version')) + 1;
            $row = ImportTemplate::create([
                'entity' => $entity, 'name' => $name, 'source_code' => $code, 'version' => $version,
                'column_mapping' => $columns, 'value_maps' => $maps === [] ? null : $maps, 'default_values' => $defaults === [] ? null : $defaults,
                'description' => is_string($input['description'] ?? null) && trim($input['description']) !== '' ? trim($input['description']) : null,
                'is_system' => false, 'created_by_user_id' => $userId,
            ]);
            $this->audit->record('import.profile_saved', 'import_template', $row->id, $previous === null ? null : [
                'version' => $previous['version'], 'columns' => $previous['columns'], 'value_maps' => $previous['value_maps'], 'defaults' => $previous['defaults'],
            ], ['code' => $code, 'entity' => $entity, 'version' => $version, 'columns' => $columns, 'value_maps' => $maps, 'defaults' => $defaults]);

            return (array) $this->find($code, $entity);
        });
    }

    /**
     * Perfiles que reconocen las cabeceras del archivo, del más al menos parecido.
     *
     * @param  array<int, string>  $headers
     * @return list<array{code: string, name: string, version: int, score: float, to_validate: bool}>
     */
    public function detect(string $entity, array $headers): array
    {
        $found = [];
        foreach ($this->all($entity) as $profile) {
            $columns = $profile['columns'];
            if ($columns === []) {
                continue;
            }
            $mapped = count(self::mappingFor($profile, $headers));
            $score = round($mapped / count($columns), 2);
            if ($score >= self::DETECT_THRESHOLD) {
                $found[] = ['code' => $profile['code'], 'name' => $profile['name'], 'version' => $profile['version'], 'score' => $score, 'to_validate' => $profile['to_validate']];
            }
        }
        usort($found, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $found;
    }

    /**
     * Mapeo clave → cabecera real del archivo según el perfil (comparación sin tildes ni mayúsculas).
     *
     * @param  array<string, mixed>  $profile
     * @param  array<int, string>  $headers
     * @return array<string, string>
     */
    public static function mappingFor(array $profile, array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            $normalized[ImportValues::normalizeHeader($header)] ??= $header;
        }
        $mapping = [];
        foreach ($profile['columns'] as $key => $names) {
            foreach ($names as $name) {
                $header = $normalized[ImportValues::normalizeHeader($name)] ?? null;
                if ($header !== null && ! in_array($header, $mapping, true)) {
                    $mapping[(string) $key] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Aplica las equivalencias de códigos y los valores por defecto del perfil a una fila ya mapeada.
     *
     * @param  array<string, string>  $data
     * @param  array{value_maps?: array<string, array<string, string>>, defaults?: array<string, string>}  $profile
     * @return array<string, string>
     */
    public static function translate(array $data, array $profile): array
    {
        foreach ($profile['value_maps'] ?? [] as $key => $map) {
            $value = $data[$key] ?? '';
            if ($value === '') {
                continue;
            }
            $lookup = [];
            foreach ($map as $from => $to) {
                $lookup[self::valueKey((string) $from)] = $to;
            }
            $data[$key] = $lookup[self::valueKey($value)] ?? $value;
        }
        foreach ($profile['defaults'] ?? [] as $key => $value) {
            if (($data[$key] ?? '') === '') {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * La versión exacta de un perfil para guardarla en la importación (trazabilidad).
     *
     * @return array<string, mixed>
     */
    public function require(string $code, string $entity, string $locale): array
    {
        $profile = $this->find($code, $entity);
        if ($profile === null) {
            throw ValidationException::withMessages(['profile_code' => [(string) trans('imports.profiles.unknown', [], $locale)]]);
        }

        return $profile;
    }

    private static function valueKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, list<string>>
     */
    private static function columnsOf(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $names) {
            $list = array_values(array_filter(array_map(fn ($n) => is_scalar($n) ? trim((string) $n) : '', is_array($names) ? $names : [$names]), fn ($n) => $n !== ''));
            if (is_string($key) && $list !== []) {
                $out[$key] = $list;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, array<string, string>>
     */
    private static function mapsOf(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $map) {
            if (! is_string($key) || ! is_array($map)) {
                continue;
            }
            foreach ($map as $from => $to) {
                if (is_scalar($to) && trim((string) $from) !== '') {
                    $out[$key][(string) $from] = trim((string) $to);
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, string>
     */
    private static function defaultsOf(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = trim((string) $value);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromTemplate(ImportTemplate $row, bool $overridesShipped): array
    {
        $shipped = $this->shipped()[(string) $row->source_code] ?? null;

        return [
            'code' => (string) $row->source_code, 'entity' => $row->entity, 'name' => (string) $row->name,
            'description' => $row->description, 'version' => (int) $row->version,
            'origin' => 'organization', 'to_validate' => $overridesShipped ? (bool) ($shipped['por_validar'] ?? true) : false,
            'columns' => self::columnsOf($row->column_mapping),
            'value_maps' => self::mapsOf($row->value_maps ?? []),
            'defaults' => self::defaultsOf($row->default_values ?? []),
            'template_id' => $row->id,
        ];
    }

    /** @return list<string> */
    private function codes(): array
    {
        $own = ImportTemplate::query()->whereNotNull('source_code')->distinct()->pluck('source_code')->map(fn ($c) => (string) $c)->all();

        return array_values(array_unique(array_merge(array_keys($this->shipped()), $own)));
    }

    /** @return list<string> */
    private function entitiesOf(string $code): array
    {
        $own = ImportTemplate::query()->where('source_code', $code)->distinct()->pluck('entity')->map(fn ($e) => (string) $e)->all();
        $shipped = array_keys($this->shipped()[$code]['entidades'] ?? []);

        return array_values(array_unique(array_merge(array_map('strval', $shipped), $own)));
    }

    /**
     * Perfiles del paquete del país de la organización, por código.
     *
     * @return array<string, array<string, mixed>>
     */
    private function shipped(): array
    {
        static $cache = [];
        TenancyScope::requireTenant();
        $country = strtolower((string) (DB::table('current_tenant')->value('country_code') ?? ''));
        if (preg_match('/^[a-z]{2}$/', $country) !== 1) {
            return [];
        }
        if (! isset($cache[$country])) {
            $file = base_path('packages/country-'.$country.'/data/perfiles_importacion.php');
            $list = is_file($file) ? require $file : [];
            $cache[$country] = [];
            foreach (is_array($list) ? $list : [] as $profile) {
                if (is_array($profile) && is_string($profile['codigo'] ?? null)) {
                    $cache[$country][$profile['codigo']] = $profile;
                }
            }
        }

        return $cache[$country];
    }
}
