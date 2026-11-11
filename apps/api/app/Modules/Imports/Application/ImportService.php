<?php

namespace App\Modules\Imports\Application;

use App\Modules\Imports\Domain\ImportJobStateMachine;
use App\Modules\Imports\Infrastructure\Models\ImportJob;
use App\Modules\Imports\Infrastructure\Models\ImportJobRow;
use App\Modules\Imports\Infrastructure\Models\ImportTemplate;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use App\Shared\Imports\SpreadsheetException;
use App\Shared\Imports\SpreadsheetReader;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IMP-1 · Motor de importación por pasos: subir → mapear → validar (genera la vista previa) →
 * confirmar (se importa en segundo plano por lotes) → deshacer.
 *
 * - RN-IMP-01: no hay forma de importar sin validar; «execute» solo existe desde «validated».
 * - RN-IMP-02: con filas con error, la política importacion.politica_errores decide si se
 *   importan solo las válidas o si hay que corregir todo antes.
 * - RN-IMP-04: una columna sensible sin el permiso del campo se ignora con aviso.
 * - RN-IMP-07: deshacer solo si ningún registro del lote está en uso; si no, se dice qué lo impide.
 * - RN-IMP-08: el mismo archivo subido dos veces se detecta por su huella y se advierte.
 * Cada paso deja auditoría; validar, confirmar, terminar, fallar y deshacer emiten su evento.
 */
final class ImportService
{
    private const ROW_CHUNK = 1000;

    public function __construct(
        private readonly ImportRegistry $importers,
        private readonly ImportJobStateMachine $machine,
        private readonly SpreadsheetReader $reader,
        private readonly FileService $files,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
        private readonly ParameterResolver $parameters,
    ) {}

    /**
     * Tipos que el usuario puede importar, con sus columnas para armar la plantilla.
     *
     * @return array<int, array<string, mixed>>
     */
    public function types(EffectivePermissions $permissions, string $locale): array
    {
        $out = [];
        foreach ($this->importers->all() as $importer) {
            if (! $permissions->has($importer->permission())) {
                continue;
            }
            $entity = $importer->entity();
            $out[] = [
                'entity' => $entity,
                'label' => (string) trans("imports.entities.{$entity}", [], $locale),
                'columns' => array_map(fn ($c) => [
                    'key' => $c->key,
                    'label' => $this->columnLabel($entity, $c->key, $locale),
                    'required' => $c->required,
                    'example' => $c->example,
                    'sensitive' => $c->fieldPermission !== null,
                    'allowed' => $c->fieldPermission === null || $permissions->has($c->fieldPermission),
                ], $importer->columns()),
                'error_policy' => $this->errorPolicy(),
            ];
        }

        return $out;
    }

    public function errorPolicy(): string
    {
        return $this->parameters->value('importacion.politica_errores') === 'fix_all' ? 'fix_all' : 'only_valid';
    }

    /**
     * Paso 1: subir el archivo. Se lee, se guarda en el almacenamiento privado y sus filas quedan
     * pendientes de mapear y validar. Nada se escribe en el módulo dueño.
     */
    public function create(string $entity, string $fileName, string $contents, string $userId, EffectivePermissions $permissions, string $locale): ImportJob
    {
        $this->importer($entity, $permissions, $locale);
        $fileName = trim($fileName) !== '' ? trim($fileName) : 'import.csv';

        try {
            $table = $this->reader->read($contents, $fileName, $this->maxRows());
        } catch (SpreadsheetException $e) {
            throw ValidationException::withMessages(['content_base64' => [(string) trans('imports.file.'.$e->reason, $e->params, $locale)]]);
        }

        $tenantId = TenancyScope::requireTenant();
        $mime = $table['format'] === SpreadsheetReader::FORMAT_XLSX ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv';
        $sha = hash('sha256', $contents);
        // RN-IMP-08: mismo archivo para el mismo tipo de datos, en una importación que no se descartó.
        $duplicateOf = DB::table('import_jobs as j')->join('stored_files as f', 'f.id', '=', 'j.stored_file_id')
            ->where('j.entity', $entity)->where('f.sha256', $sha)->whereNotIn('j.status', ['cancelled'])
            ->orderByDesc('j.created_at')->value('j.id');

        return DB::transaction(function () use ($entity, $fileName, $contents, $mime, $table, $userId, $tenantId, $duplicateOf, $locale): ImportJob {
            $file = $this->files->upload($contents, $fileName, $mime, $tenantId, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);
            $warnings = [];
            if ($duplicateOf !== null) {
                $warnings[] = ['code' => 'duplicate_file', 'message' => (string) trans('imports.warnings.duplicate_file', [], $locale)];
            }
            $job = ImportJob::create([
                'entity' => $entity,
                'stored_file_id' => $file->id,
                'file_name' => $fileName,
                'headers' => $table['headers'],
                'status' => 'uploaded',
                'total_rows' => count($table['rows']),
                'created_by_user_id' => $userId,
                'batch_size' => $this->batchSize(),
                'dry_run' => false,
                'summary' => [
                    'format' => $table['format'], 'delimiter' => $table['delimiter'],
                    'duplicate_of' => $duplicateOf, 'warnings' => $warnings, 'ignored_columns' => [],
                ],
            ]);

            $now = now();
            foreach (array_chunk($table['rows'], self::ROW_CHUNK, true) as $chunk) {
                $insert = [];
                foreach ($chunk as $line => $cells) {
                    $insert[] = [
                        'tenant_id' => $tenantId, 'import_job_id' => $job->id,
                        'row_number' => $line, 'raw_data' => json_encode($cells, JSON_UNESCAPED_UNICODE), 'status' => 'pending',
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                DB::table('import_job_rows')->insert($insert);
            }

            $this->audit->record('import.created', 'import_job', $job->id, null, [
                'entity' => $entity, 'file_name' => $fileName, 'total_rows' => $job->total_rows, 'duplicate_of' => $duplicateOf,
            ]);

            return $job;
        });
    }

    /**
     * Mapeo propuesto: el último guardado para este tipo de datos o el de la última importación
     * (si sus cabeceras están en este archivo) y, para lo que falte, coincidencia por nombre.
     *
     * @return array<string, string>
     */
    public function suggestedMapping(ImportJob $job): array
    {
        $importer = $this->importers->get($job->entity);
        if ($importer === null) {
            return [];
        }
        $headers = $job->headers ?? [];
        $keys = array_map(fn ($c) => $c->key, $importer->columns());
        $suggested = [];

        $previous = ImportTemplate::query()->where('entity', $job->entity)->orderByDesc('updated_at')->value('column_mapping');
        if (! is_array($previous)) {
            $last = ImportJob::query()->where('entity', $job->entity)->where('id', '!=', $job->id)->whereNotNull('column_mapping')
                ->orderByDesc('created_at')->value('column_mapping');
            $previous = is_string($last) ? json_decode($last, true) : $last;
        }
        if (is_array($previous)) {
            foreach ($previous as $key => $header) {
                if (in_array($key, $keys, true) && is_string($header) && in_array($header, $headers, true)) {
                    $suggested[(string) $key] = $header;
                }
            }
        }

        $normalized = [];
        foreach ($headers as $header) {
            $normalized[ImportValues::normalizeHeader($header)] = $header;
        }
        foreach ($importer->columns() as $column) {
            if (isset($suggested[$column->key])) {
                continue;
            }
            $names = array_merge([$column->key], $column->aliases);
            foreach (['es', 'en', 'pt'] as $lang) {
                $names[] = $this->columnLabel($job->entity, $column->key, $lang);
            }
            foreach ($names as $name) {
                $n = ImportValues::normalizeHeader($name);
                if (isset($normalized[$n]) && ! in_array($normalized[$n], $suggested, true)) {
                    $suggested[$column->key] = $normalized[$n];
                    break;
                }
            }
        }

        return $suggested;
    }

    /**
     * Paso 2: mapear columnas (clave de columna → cabecera del archivo).
     *
     * @param  array<mixed>  $mapping
     */
    public function map(ImportJob $job, array $mapping, ?string $saveAs, EffectivePermissions $permissions, string $locale): ImportJob
    {
        $importer = $this->importer($job->entity, $permissions, $locale);
        $next = $this->machine->getNextState($job->status, 'map');
        $columns = [];
        foreach ($importer->columns() as $column) {
            $columns[$column->key] = $column;
        }
        $headers = $job->headers ?? [];

        $errors = [];
        $clean = [];
        foreach ($mapping as $key => $header) {
            if ($header === null || $header === '') {
                continue;
            }
            if (! is_string($key) || ! isset($columns[$key])) {
                $errors['mapping.'.$key][] = (string) trans('imports.mapping.unknown_column', ['column' => (string) $key], $locale);

                continue;
            }
            if (! is_string($header) || ! in_array($header, $headers, true)) {
                $errors['mapping.'.$key][] = (string) trans('imports.mapping.unknown_header', ['header' => is_scalar($header) ? (string) $header : ''], $locale);

                continue;
            }
            if (in_array($header, $clean, true)) {
                $errors['mapping.'.$key][] = (string) trans('imports.mapping.header_twice', ['header' => $header], $locale);

                continue;
            }
            $clean[$key] = $header;
        }

        // RN-IMP-04: sin el permiso del campo sensible, la columna no se importa (se avisa).
        $ignored = [];
        foreach ($clean as $key => $header) {
            $permission = $columns[$key]->fieldPermission;
            if ($permission !== null && ! $permissions->has($permission)) {
                $ignored[] = $key;
                unset($clean[$key]);
            }
        }
        foreach ($columns as $key => $column) {
            if ($column->required && ! isset($clean[$key]) && ! in_array($key, $ignored, true)) {
                $errors['mapping.'.$key][] = (string) trans('imports.mapping.missing_required', ['column' => $this->columnLabel($job->entity, $key, $locale)], $locale);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($job, $clean, $ignored, $next, $saveAs, $locale): ImportJob {
            $summary = $job->summary ?? [];
            $summary['ignored_columns'] = $ignored;
            $summary['warnings'] = array_values(array_filter($summary['warnings'] ?? [], fn ($w) => ($w['code'] ?? '') !== 'sensitive_ignored'));
            foreach ($ignored as $key) {
                $summary['warnings'][] = ['code' => 'sensitive_ignored', 'column' => $key,
                    'message' => (string) trans('imports.warnings.sensitive_ignored', ['column' => $this->columnLabel($job->entity, $key, $locale)], $locale)];
            }
            $templateId = null;
            if ($saveAs !== null && trim($saveAs) !== '') {
                $template = ImportTemplate::query()->firstOrNew(['entity' => $job->entity, 'name' => trim($saveAs)]);
                $template->fill(['column_mapping' => $clean, 'is_system' => false])->save();
                $templateId = $template->id;
            }
            $before = $this->snapshot($job);
            $job->forceFill([
                'column_mapping' => $clean, 'status' => $next, 'summary' => $summary,
                'import_template_id' => $templateId ?? $job->import_template_id,
            ])->save();
            $this->audit->record('import.mapped', 'import_job', $job->id, $before, $this->snapshot($job));

            return $job;
        });
    }

    /**
     * Paso 3: validar fila por fila sin escribir nada y dejar la vista previa.
     */
    public function validate(ImportJob $job, EffectivePermissions $permissions, string $locale): ImportJob
    {
        $importer = $this->importer($job->entity, $permissions, $locale);
        if (! $this->machine->can($job->status, 'validate')) {
            $this->machine->getNextState($job->status, 'validate');
        }
        $mapping = $job->column_mapping ?? [];
        $index = array_flip($job->headers ?? []);
        $context = new ImportContext($job->tenant_id, $job->id, $job->created_by_user_id, $locale);
        $required = array_map(fn ($c) => $c->key, array_filter($importer->columns(), fn ($c) => $c->required));

        $valid = 0;
        $invalid = 0;
        $seen = [];
        ImportJobRow::query()->where('import_job_id', $job->id)
            ->chunkById(self::ROW_CHUNK, function ($rows) use ($importer, $mapping, $index, $context, $required, $locale, &$valid, &$invalid, &$seen): void {
                foreach ($rows as $row) {
                    /** @var ImportJobRow $row */
                    $data = self::rowData($row->raw_data, $mapping, $index);
                    $check = new RowCheck;
                    foreach ($required as $key) {
                        if (($data[$key] ?? '') === '') {
                            $check->error($key, 'required', (string) trans('imports.errors.required', ['column' => $this->columnLabel($importer->entity(), $key, $locale)], $locale));
                        }
                    }
                    if ($check->ok()) {
                        $check = $importer->validate($data, $context);
                    }
                    $natural = $check->ok() ? $importer->naturalKey($data) : null;
                    if ($natural !== null) {
                        if (isset($seen[$natural])) {
                            $check->error(null, 'duplicate_in_file', (string) trans('imports.errors.duplicate_in_file', ['row' => $seen[$natural]], $locale));
                        } else {
                            $seen[$natural] = $row->row_number;
                        }
                    }
                    $ok = $check->ok();
                    $ok ? $valid++ : $invalid++;
                    $row->forceFill([
                        'status' => $ok ? 'valid' : 'invalid',
                        'parsed_data' => $ok ? $check->parsed : null,
                        'errors' => $check->errors === [] ? null : $check->errors,
                        'warnings' => $check->warnings === [] ? null : $check->warnings,
                    ])->save();
                }
            }, 'row_number');

        $policy = $this->errorPolicy();
        $action = ($invalid > 0 && ($policy === 'fix_all' || $valid === 0)) ? 'validate_with_errors' : 'validate';
        $next = $this->machine->getNextState($job->status, $action);

        return DB::transaction(function () use ($job, $valid, $invalid, $next, $policy): ImportJob {
            $before = $this->snapshot($job);
            $summary = $job->summary ?? [];
            $summary['error_policy'] = $policy;
            $job->forceFill(['status' => $next, 'valid_rows' => $valid, 'error_rows' => $invalid])->save();
            $job->forceFill(['summary' => $summary])->save();
            $this->outbox->record(DomainEventTypes::IMPORT_VALIDATED, 'import_job', $job->id, [
                'import_job_id' => $job->id, 'entity' => $job->entity, 'valid_rows' => $valid, 'error_rows' => $invalid,
            ]);
            $this->audit->record('import.validated', 'import_job', $job->id, $before, $this->snapshot($job));

            return $job;
        });
    }

    /**
     * Paso 4: confirmar. Exige la vista previa revisada (confirm=true) y el permiso del módulo dueño;
     * el trabajo sigue en segundo plano (evento import.started → ImportRunner).
     */
    public function execute(ImportJob $job, bool $confirmed, EffectivePermissions $permissions, string $locale): ImportJob
    {
        $this->importer($job->entity, $permissions, $locale);
        $next = $this->machine->getNextState($job->status, 'execute');
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirm' => [(string) trans('imports.confirm_required', [], $locale)]]);
        }
        if ((int) $job->valid_rows === 0) {
            throw new AyniException('business_rule_violated', 422, (string) trans('imports.nothing_to_import', [], $locale), [], ['rule' => 'RN-IMP-02']);
        }

        return DB::transaction(function () use ($job, $next): ImportJob {
            $before = $this->snapshot($job);
            $job->forceFill(['status' => $next, 'started_at' => now(), 'batch_size' => $this->batchSize(), 'error_message' => null])->save();
            $event = $this->outbox->record(DomainEventTypes::IMPORT_STARTED, 'import_job', $job->id, ['import_job_id' => $job->id, 'entity' => $job->entity]);
            $this->outbox->deliverSoon($event);
            $this->audit->record('import.started', 'import_job', $job->id, $before, $this->snapshot($job));

            return $job;
        });
    }

    /**
     * Deshacer el lote (RN-IMP-07): solo si ningún registro está en uso; si alguno lo está, se
     * informa qué filas y por qué, y no se deshace nada.
     */
    public function revert(ImportJob $job, EffectivePermissions $permissions, string $locale): ImportJob
    {
        $importer = $this->importer($job->entity, $permissions, $locale);
        $next = $this->machine->getNextState($job->status, 'revert');
        $context = new ImportContext($job->tenant_id, $job->id, $job->created_by_user_id, $locale);
        $rows = ImportJobRow::query()->where('import_job_id', $job->id)->where('status', 'imported')->whereNotNull('entity_id')
            ->orderByDesc('row_number')->get();

        $blocked = [];
        foreach ($rows as $row) {
            $reason = $importer->blockingReason((string) $row->entity_id, $context);
            if ($reason !== null) {
                $blocked[] = ['row_number' => $row->row_number, 'reason' => $reason];
            }
        }
        if ($blocked !== []) {
            throw new AyniException('business_rule_violated', 422, (string) trans('imports.revert_blocked', ['n' => count($blocked)], $locale), [], ['rule' => 'RN-IMP-07', 'blocked' => array_slice($blocked, 0, 50)]);
        }

        return DB::transaction(function () use ($job, $importer, $rows, $context, $next): ImportJob {
            $before = $this->snapshot($job);
            foreach ($rows as $row) {
                $importer->revert((string) $row->entity_id, $row->previous_data, $context);
            }
            $job->forceFill(['status' => $next])->save();
            $this->outbox->record(DomainEventTypes::IMPORT_REVERTED, 'import_job', $job->id, [
                'import_job_id' => $job->id, 'entity' => $job->entity, 'reverted_rows' => $rows->count(),
            ]);
            $this->audit->record('import.reverted', 'import_job', $job->id, $before, $this->snapshot($job) + ['reverted_rows' => $rows->count()]);

            return $job;
        });
    }

    /**
     * Descartar: borra el archivo temporal y las filas leídas (no se importó nada).
     */
    public function cancel(ImportJob $job): ImportJob
    {
        $next = $this->machine->getNextState($job->status, 'cancel');

        return DB::transaction(function () use ($job, $next): ImportJob {
            $before = $this->snapshot($job);
            DB::table('import_job_rows')->where('import_job_id', $job->id)->delete();
            $job->forceFill(['status' => $next])->save();
            $this->files->delete($job->stored_file_id, $job->tenant_id);
            $this->audit->record('import.cancelled', 'import_job', $job->id, $before, $this->snapshot($job));

            return $job;
        });
    }

    /**
     * Filas para la vista previa (paginadas por número de fila).
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function rows(ImportJob $job, ?string $status, int $page, int $perPage): array
    {
        $query = ImportJobRow::query()->where('import_job_id', $job->id);
        if ($status !== null) {
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        $mapping = $job->column_mapping ?? [];
        $index = array_flip($job->headers ?? []);
        $rows = $query->orderBy('row_number')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $counts = ImportJobRow::query()->where('import_job_id', $job->id)->groupBy('status')->orderBy('status')
            ->selectRaw('status, count(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return [
            'data' => $rows->map(fn (ImportJobRow $r) => [
                'row_number' => $r->row_number,
                'status' => $r->status,
                'values' => $mapping === [] ? self::rowData($r->raw_data, array_combine($job->headers ?? [], $job->headers ?? []), $index) : self::rowData($r->raw_data, $mapping, $index),
                'errors' => $r->errors ?? [],
                'warnings' => $r->warnings ?? [],
                'entity_id' => $r->entity_id,
            ])->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'counts' => $counts],
        ];
    }

    /**
     * Filas con error en CSV: cabeceras originales + número de fila + motivo (criterio de IMP-1).
     */
    public function errorsCsv(ImportJob $job, string $locale): string
    {
        $headers = $job->headers ?? [];
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new AyniException('internal_error', 500);
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge([(string) trans('imports.csv.row', [], $locale)], $headers, [(string) trans('imports.csv.reason', [], $locale)]), ',', '"', '');
        ImportJobRow::query()->where('import_job_id', $job->id)->whereIn('status', ['invalid', 'failed'])
            ->chunkById(self::ROW_CHUNK, function ($rows) use ($out, $headers): void {
                foreach ($rows as $row) {
                    /** @var ImportJobRow $row */
                    $reasons = array_map(fn ($e) => ($e['column'] !== null ? $e['column'].': ' : '').$e['message'], $row->errors ?? []);
                    $cells = array_slice(array_pad($row->raw_data, count($headers), ''), 0, count($headers));
                    fputcsv($out, array_map([self::class, 'safeCell'], array_merge([(string) $row->row_number], $cells, [implode(' · ', $reasons)])), ',', '"', '');
                }
            }, 'row_number');
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record('import.errors_downloaded', 'import_job', $job->id, null, ['error_rows' => $job->error_rows]);

        return $csv;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ImportJob $job): array
    {
        $author = DB::table('users')->where('id', $job->created_by_user_id)->value('display_name');

        return [
            'id' => $job->id,
            'entity' => $job->entity,
            'file_name' => $job->file_name,
            'file_id' => $job->status === 'cancelled' ? null : $job->stored_file_id,
            'headers' => $job->headers ?? [],
            'mapping' => (object) ($job->column_mapping ?? []),
            'suggested_mapping' => (object) (in_array($job->status, ['uploaded', 'mapped'], true) ? $this->suggestedMapping($job) : []),
            'status' => $job->status,
            'total_rows' => $job->total_rows,
            'valid_rows' => $job->valid_rows,
            'error_rows' => $job->error_rows,
            'imported_rows' => $job->imported_rows,
            'batch_size' => $job->batch_size,
            'summary' => (object) ($job->summary ?? []),
            'error_message' => $job->error_message,
            'created_by_user_id' => $job->created_by_user_id,
            'created_by_name' => is_string($author) ? $author : null,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'allowed_actions' => $this->machine->userActions($job->status),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Valores de la fila por clave de columna según el mapeo.
     *
     * @param  array<int, string>  $raw
     * @param  array<string, string>  $mapping
     * @param  array<string, int>  $index
     * @return array<string, string>
     */
    public static function rowData(array $raw, array $mapping, array $index): array
    {
        $data = [];
        foreach ($mapping as $key => $header) {
            $position = $index[$header] ?? null;
            $data[$key] = $position === null ? '' : trim((string) ($raw[$position] ?? ''));
        }

        return $data;
    }

    /** Evita que una celda se ejecute como fórmula al abrir el CSV en una hoja de cálculo. */
    public static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    public function batchSize(): int
    {
        return max(1, (int) ($this->parameters->value('importacion.tamano_lote') ?? 500));
    }

    private function maxRows(): int
    {
        return max(1, (int) ($this->parameters->value('importacion.max_filas') ?? 50000));
    }

    private function columnLabel(string $entity, string $key, string $locale): string
    {
        $k = "imports.columns.{$entity}.{$key}";
        $label = (string) trans($k, [], $locale);

        return $label === $k ? $key : $label;
    }

    /**
     * El importador del tipo de datos y el permiso del módulo dueño (regla 10: se valida en el servidor).
     */
    private function importer(string $entity, EffectivePermissions $permissions, string $locale): Importer
    {
        $importer = $this->importers->get($entity);
        if ($importer === null) {
            throw ValidationException::withMessages(['entity' => [(string) trans('imports.entity_invalid', [], $locale)]]);
        }
        if (! $permissions->has($importer->permission())) {
            throw new AyniException('forbidden', 403, null, [], ['permission' => $importer->permission()]);
        }

        return $importer;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ImportJob $job): array
    {
        return [
            'status' => $job->status, 'entity' => $job->entity, 'mapping' => $job->column_mapping,
            'total_rows' => $job->total_rows, 'valid_rows' => $job->valid_rows, 'error_rows' => $job->error_rows, 'imported_rows' => $job->imported_rows,
        ];
    }
}
