<?php

namespace App\Modules\Reports\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEG-12 · Exportaciones controladas. Cada exportación:
 *  - exige reports.exports.create y además el permiso de ver el listado (la organización decide
 *    qué roles exportan asignando o quitando reports.exports.create);
 *  - respeta alcance y permisos por campo (cada módulo registra su ExportSource);
 *  - queda en export_jobs con filtros y número de filas, en la auditoría y, si incluye campos
 *    sensibles, en el registro de accesos con el canal «export»;
 *  - lleva marca de agua con el nombre de quien exporta si la organización lo activa.
 * Se genera en la misma solicitud: los listados exportables están acotados por
 * security.export_max_rows. Cada descarga posterior también se cuenta y se registra.
 */
final class ExportService
{
    public const FORMATS = ['csv'];

    public function __construct(
        private readonly ExportRegistry $sources,
        private readonly FileService $files,
        private readonly AuditLogger $audit,
        private readonly ParameterResolver $parameters,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, Request $request, ExportActor $user, EffectivePermissions $permissions): \stdClass
    {
        $resource = is_string($input['resource'] ?? null) ? $input['resource'] : '';
        $format = is_string($input['format'] ?? null) ? $input['format'] : 'csv';
        $rawFilters = $input['filters'] ?? [];
        $locale = $user->locale;
        $source = $this->sources->get($resource);

        if ($source === null) {
            throw ValidationException::withMessages(['resource' => [trans('exports.resource_invalid', [], $locale)]]);
        }
        if (! in_array($format, self::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => [trans('exports.format_invalid', [], $locale)]]);
        }
        if (! is_array($rawFilters)) {
            throw ValidationException::withMessages(['filters' => [trans('exports.filter_invalid', ['filter' => 'filters'], $locale)]]);
        }
        $filters = [];
        foreach ($rawFilters as $key => $value) {
            if (! in_array($key, $source->filters(), true) || ! (is_string($value) || is_int($value))) {
                throw ValidationException::withMessages(['filters.'.$key => [trans('exports.filter_invalid', ['filter' => (string) $key], $locale)]]);
            }
            $filters[(string) $key] = trim((string) $value);
        }
        $filters = array_filter($filters, fn ($v) => $v !== '');

        // Exportar no da más acceso que la pantalla: hace falta el permiso del listado.
        $resourcePermission = $source->permission();
        if (! $permissions->has($resourcePermission)) {
            throw new AyniException('forbidden', 403, null, [], ['permission' => $resourcePermission]);
        }

        $limit = max(1, (int) config('security.export_max_rows', 50000));
        $data = $source->build($filters, $request, $permissions, $limit);
        $sensitive = $data['sensitive'] !== [] && $data['rows'] !== [];

        $csv = $this->csv($data['columns'], $data['rows'], $locale, $this->watermark($user, $locale));
        $tenantId = TenancyScope::requireTenant();
        $name = $resource.'-'.now()->format('Ymd-His').'.csv';

        return DB::transaction(function () use ($resource, $format, $filters, $data, $sensitive, $csv, $tenantId, $name, $user, $request): \stdClass {
            $file = $this->files->upload($csv, $name, 'text/csv', $tenantId, $user->id,
                $sensitive ? StoredFile::CLASSIFICATION_CONFIDENTIAL : StoredFile::CLASSIFICATION_INTERNAL);

            $id = (string) DB::table('export_jobs')->insertGetId([
                'tenant_id' => $tenantId,
                'resource' => $resource,
                'format' => $format,
                'filters' => json_encode((object) $filters, JSON_THROW_ON_ERROR),
                'columns' => json_encode($data['columns'], JSON_THROW_ON_ERROR),
                'requested_by_user_id' => $user->id,
                'status' => 'completed',
                'stored_file_id' => $file->id,
                'row_count' => count($data['rows']),
                'contains_sensitive' => $sensitive,
                'expires_at' => now()->addDays(max(1, (int) config('security.export_ttl_days', 7))),
            ], 'id');

            // Regla 7: los campos sensibles exportados quedan en el registro de accesos.
            if ($sensitive) {
                DB::table('data_access_logs')->insert([
                    'tenant_id' => $tenantId, 'user_id' => $user->id, 'access_kind' => 'export', 'data_category' => 'other',
                    'entity_type' => 'export_job', 'entity_id' => $id, 'fields' => '{'.implode(',', $data['sensitive']).'}',
                    'purpose' => $resource, 'ip' => $request->ip(), 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
                ]);
            }

            $this->audit->record('export.created', 'export_job', $id, null, [
                'resource' => $resource, 'format' => $format, 'filters' => $filters, 'row_count' => count($data['rows']),
                'columns' => $data['columns'], 'contains_sensitive' => $sensitive, 'truncated' => $data['truncated'],
            ]);

            return $this->find($id) ?? throw new AyniException('not_found', 404);
        });
    }

    public function find(string $id): ?\stdClass
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        /** @var \stdClass|null */
        return DB::table('export_jobs as j')->leftJoin('users as u', 'u.id', '=', 'j.requested_by_user_id')
            ->where('j.id', $id)->select(['j.*', 'u.display_name as requested_by_name'])->first();
    }

    /**
     * Contenido para descargar; cuenta la descarga y la deja en la auditoría.
     *
     * @return array{name: string, mime: string, contents: string}
     */
    public function download(\stdClass $job, ExportActor $user): array
    {
        $locale = $user->locale;
        if ($job->status !== 'completed' || $job->stored_file_id === null) {
            throw new AyniException('invalid_transition', 409, trans('exports.not_ready', [], $locale));
        }
        if ($job->expires_at !== null && now()->greaterThan((string) $job->expires_at)) {
            throw new AyniException('export_expired', 409, trans('exports.expired', [], $locale));
        }

        $file = $this->files->contentsForDownload((string) $job->stored_file_id, (string) $job->tenant_id, $user->id);
        DB::table('export_jobs')->where('id', $job->id)->update(['download_count' => DB::raw('download_count + 1')]);
        $this->audit->record('export.downloaded', 'export_job', (string) $job->id, null, [
            'resource' => $job->resource, 'row_count' => $job->row_count, 'download' => ((int) $job->download_count) + 1,
        ]);

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(\stdClass $job): array
    {
        $filters = json_decode((string) ($job->filters ?? '{}'), true);

        return [
            'id' => (string) $job->id,
            'resource' => (string) $job->resource,
            'format' => (string) $job->format,
            'filters' => (object) (is_array($filters) ? $filters : []),
            'row_count' => $job->row_count === null ? null : (int) $job->row_count,
            'contains_sensitive' => (bool) $job->contains_sensitive,
            'requested_by_user_id' => (string) $job->requested_by_user_id,
            'requested_by_name' => $job->requested_by_name ?? null,
            'file_id' => $job->stored_file_id === null ? null : (string) $job->stored_file_id,
            'download_url' => $job->status === 'completed' ? '/api/v1/exports/'.$job->id.'/download' : null,
            'download_count' => (int) $job->download_count,
            'expires_at' => $job->expires_at === null ? null : (new \DateTimeImmutable((string) $job->expires_at))->format(DATE_ATOM),
            'error' => $job->error,
            'status' => (string) $job->status,
            'created_at' => (new \DateTimeImmutable((string) $job->created_at))->format(DATE_ATOM),
        ];
    }

    private function watermark(ExportActor $user, string $locale): ?string
    {
        if ($this->parameters->value('seguridad.marca_agua_descargas') !== true) {
            return null;
        }

        return trans('exports.watermark', ['name' => $user->name, 'date' => now()->utc()->format('Y-m-d H:i').' UTC'], $locale);
    }

    /**
     * CSV en UTF-8 con BOM (Excel lo abre con tildes). Las celdas que empiezan con = + - @ se
     * neutralizan para evitar inyección de fórmulas al abrir el archivo.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     */
    private function csv(array $columns, array $rows, string $locale, ?string $watermark): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('No se pudo generar el archivo.');
        }
        $safe = fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v;
        if ($watermark !== null) {
            fputcsv($out, [$watermark], ',', '"', '');
        }
        fputcsv($out, array_map(function (string $c) use ($locale): string {
            $key = 'exports.columns.'.$c;
            $label = trans($key, [], $locale);

            return $label === $key ? $c : (string) $label;
        }, $columns), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map($safe, $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\u{FEFF}".$csv;
    }
}
