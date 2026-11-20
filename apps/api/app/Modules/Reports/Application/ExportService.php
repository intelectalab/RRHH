<?php

namespace App\Modules\Reports\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Exports\SimpleXlsx;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEG-12 · UI-11 · Servicio común de exportación. Cada módulo solo declara su consulta y columnas
 * (ExportSource); el servicio hace el resto. Cada exportación:
 *  - exige reports.exports.create y además el permiso de ver el listado (la organización decide
 *    qué roles exportan asignando o quitando reports.exports.create);
 *  - respeta alcance y permisos por campo: lo que no se ve en la pantalla no sale en el archivo;
 *  - sale en CSV, Excel (.xlsx) o PDF;
 *  - si pasa el umbral exportaciones.umbral_segundo_plano (RN-UI-08), se genera en segundo plano
 *    (evento export.requested) y avisa con un enlace cuando está lista, sin bloquear la interfaz;
 *  - queda en export_jobs con filtros y número de filas, en la auditoría y, si incluye campos
 *    sensibles, en el registro de accesos con el canal «export»;
 *  - lleva marca de agua con el nombre de quien exporta si la organización lo activa.
 * Cada descarga posterior también se cuenta y se registra.
 */
final class ExportService
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public const READY = 'exports.ready';

    public const FAILED = 'exports.failed';

    private const MIME = [
        'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly ExportRegistry $sources,
        private readonly FileService $files,
        private readonly AuditLogger $audit,
        private readonly ParameterResolver $parameters,
        private readonly OutboxService $outbox,
        private readonly PermissionResolver $resolver,
        private readonly Notifier $notifier,
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

        // RN-UI-08: hasta el umbral se genera en la misma solicitud; por encima, en segundo plano.
        $threshold = $this->threshold();
        $data = $source->build($filters, $request, $permissions, min($threshold + 1, $this->maxRows()));
        $tenantId = TenancyScope::requireTenant();

        if (count($data['rows']) <= $threshold) {
            return DB::transaction(function () use ($resource, $format, $filters, $data, $tenantId, $user, $request): \stdClass {
                $id = (string) DB::table('export_jobs')->insertGetId([
                    'tenant_id' => $tenantId, 'resource' => $resource, 'format' => $format,
                    'filters' => json_encode((object) $filters, JSON_THROW_ON_ERROR),
                    'requested_by_user_id' => $user->id, 'status' => 'running',
                ], 'id');
                $this->complete($id, $resource, $format, $filters, $data, $user, $request->ip());

                return $this->find($id) ?? throw new AyniException('not_found', 404);
            });
        }

        return DB::transaction(function () use ($resource, $format, $filters, $tenantId, $user, $request): \stdClass {
            $id = (string) DB::table('export_jobs')->insertGetId([
                'tenant_id' => $tenantId, 'resource' => $resource, 'format' => $format,
                'filters' => json_encode((object) $filters, JSON_THROW_ON_ERROR),
                'requested_by_user_id' => $user->id, 'status' => 'queued',
            ], 'id');
            $this->outbox->record(DomainEventTypes::EXPORT_REQUESTED, 'export_job', $id, [
                'export_id' => $id, 'resource' => $resource,
                'legal_entity_id' => DataScope::selectedLegalEntity($request), 'ip' => $request->ip(),
            ]);
            $this->audit->record('export.queued', 'export_job', $id, null, ['resource' => $resource, 'format' => $format, 'filters' => $filters]);

            return $this->find($id) ?? throw new AyniException('not_found', 404);
        });
    }

    /**
     * Genera en segundo plano una exportación en cola (evento export.requested). Toma solo trabajos en cola,
     * así una entrega doble no genera dos archivos. Vuelve a calcular permisos y alcance de quien la pidió:
     * si ya no puede ver el listado, la exportación falla sin datos.
     */
    public function run(string $id, ?string $legalEntityId, ?string $ip): void
    {
        $taken = DB::table('export_jobs')->where('id', $id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]);
        if ($taken === 0) {
            return;
        }
        /** @var \stdClass $job */
        $job = DB::table('export_jobs')->where('id', $id)->first();
        $userId = (string) $job->requested_by_user_id;
        $actor = $this->actor($userId);
        $label = $this->label((string) $job->resource, $actor->locale);

        $previous = app('request');
        $request = Request::create('/', 'GET', $legalEntityId === null ? [] : ['legal_entity_id' => $legalEntityId], [], [], ['REMOTE_ADDR' => $ip ?? '127.0.0.1']);
        $request->setUserResolver(fn () => new GenericUser(['id' => $userId]));
        app()->instance('request', $request);

        try {
            $source = $this->sources->get((string) $job->resource);
            $permissions = $this->resolver->for($userId);
            if ($source === null || ! $permissions->has('reports.exports.create') || ! $permissions->has($source->permission())) {
                throw new AyniException('forbidden', 403);
            }
            $decoded = json_decode((string) ($job->filters ?? '{}'), true);
            $filters = [];
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                $filters[(string) $key] = is_scalar($value) ? (string) $value : '';
            }
            $data = $source->build($filters, $request, $permissions, $this->maxRows());
            DB::transaction(fn () => $this->complete($id, (string) $job->resource, (string) $job->format, $filters, $data, $actor, $ip));
            $this->notifier->notify(self::READY, [$userId], ['resource' => $label, 'rows' => count($data['rows']), 'export_id' => $id], 'export_job', $id);
        } catch (\Throwable $e) {
            report($e);
            DB::table('export_jobs')->where('id', $id)->update(['status' => 'failed', 'error' => (string) trans('exports.failed_reason', [], $actor->locale), 'updated_at' => now()]);
            $this->notifier->notify(self::FAILED, [$userId], ['resource' => $label, 'export_id' => $id], 'export_job', $id);
        } finally {
            app()->instance('request', $previous);
        }
    }

    /**
     * Escribe el archivo y cierra el trabajo (dentro de una transacción).
     *
     * @param  array<string, string>  $filters
     * @param  array{columns: array<int, string>, rows: array<int, array<int, string>>, sensitive: array<int, string>, truncated: bool}  $data
     */
    private function complete(string $id, string $resource, string $format, array $filters, array $data, ExportActor $user, ?string $ip): void
    {
        $tenantId = TenancyScope::requireTenant();
        $sensitive = $data['sensitive'] !== [] && $data['rows'] !== [];
        $contents = $this->render($format, $resource, $data['columns'], $data['rows'], $user->locale, $this->watermark($user, $user->locale));
        $name = $resource.'-'.now()->format('Ymd-His').'.'.$format;
        $file = $this->files->upload($contents, $name, self::mime($format), $tenantId, $user->id,
            $sensitive ? StoredFile::CLASSIFICATION_CONFIDENTIAL : StoredFile::CLASSIFICATION_INTERNAL);

        DB::table('export_jobs')->where('id', $id)->update([
            'columns' => json_encode($data['columns'], JSON_THROW_ON_ERROR),
            'status' => 'completed',
            'stored_file_id' => $file->id,
            'row_count' => count($data['rows']),
            'contains_sensitive' => $sensitive,
            'expires_at' => now()->addDays(max(1, (int) config('security.export_ttl_days', 7))),
            'updated_at' => now(),
        ]);

        // Regla 7: los campos sensibles exportados quedan en el registro de accesos.
        if ($sensitive) {
            DB::table('data_access_logs')->insert([
                'tenant_id' => $tenantId, 'user_id' => $user->id, 'access_kind' => 'export', 'data_category' => 'other',
                'entity_type' => 'export_job', 'entity_id' => $id, 'fields' => '{'.implode(',', $data['sensitive']).'}',
                'purpose' => $resource, 'ip' => $ip, 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        }

        $this->audit->record('export.created', 'export_job', $id, null, [
            'resource' => $resource, 'format' => $format, 'filters' => $filters, 'row_count' => count($data['rows']),
            'columns' => $data['columns'], 'contains_sensitive' => $sensitive, 'truncated' => $data['truncated'],
        ]);
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

    public static function mime(string $format): string
    {
        return self::MIME[$format] ?? 'application/octet-stream';
    }

    private function threshold(): int
    {
        $value = $this->parameters->value('exportaciones.umbral_segundo_plano');

        return max(0, is_numeric($value) ? (int) $value : 1000);
    }

    private function maxRows(): int
    {
        return max(1, (int) config('security.export_max_rows', 50000));
    }

    private function actor(string $userId): ExportActor
    {
        $row = DB::table('users')->where('id', $userId)->first(['display_name', 'locale']);

        return new ExportActor($userId, (string) ($row->display_name ?? ''), (string) (($row->locale ?? '') ?: 'es'));
    }

    private function label(string $resource, string $locale): string
    {
        $key = 'exports.resources.'.$resource;
        $label = trans($key, [], $locale);

        return $label === $key ? $resource : (string) $label;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     */
    private function render(string $format, string $resource, array $columns, array $rows, string $locale, ?string $watermark): string
    {
        $header = array_map(fn (string $c): string => $this->column($c, $locale), $columns);

        return match ($format) {
            'xlsx' => SimpleXlsx::render($header, $rows, $watermark, $this->label($resource, $locale)),
            'pdf' => $this->pdf($resource, $header, $rows, $locale, $watermark),
            default => $this->csv($columns, $rows, $locale, $watermark),
        };
    }

    /**
     * PDF de lectura: un párrafo por fila con «columna: valor», sin las columnas vacías.
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<int, string>>  $rows
     */
    private function pdf(string $resource, array $header, array $rows, string $locale, ?string $watermark): string
    {
        $blocks = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach (array_values($row) as $i => $value) {
                if ($value !== '') {
                    $parts[] = ($header[$i] ?? '').': '.$value;
                }
            }
            $blocks[] = implode(' · ', $parts);
        }
        $footer = trim(($watermark === null ? '' : $watermark.' · ').trans('exports.pdf_rows', ['count' => count($rows)], $locale));

        return SimplePdf::render($this->label($resource, $locale), $blocks === [] ? (string) trans('exports.pdf_empty', [], $locale) : implode("\n\n", $blocks), $footer);
    }

    private function column(string $c, string $locale): string
    {
        $key = 'exports.columns.'.$c;
        $label = trans($key, [], $locale);

        return $label === $key ? $c : (string) $label;
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
        fputcsv($out, array_map(fn (string $c): string => $this->column($c, $locale), $columns), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map($safe, $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\u{FEFF}".$csv;
    }
}
