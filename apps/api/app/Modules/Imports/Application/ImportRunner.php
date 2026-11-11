<?php

namespace App\Modules\Imports\Application;

use App\Modules\Imports\Domain\ImportJobStateMachine;
use App\Modules\Imports\Infrastructure\Models\ImportJob;
use App\Modules\Imports\Infrastructure\Models\ImportJobRow;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IMP-1 · Importación en segundo plano, por lotes de importacion.tamano_lote filas.
 *
 * Cada lote se confirma en su propia transacción. Si una fila falla (regla del módulo dueño,
 * límite del plan, error inesperado), ese lote se deshace solo y luego se deshacen los lotes ya
 * confirmados: la importación queda «failed» sin datos a medias, con la fila y el motivo.
 * Es idempotente: solo actúa sobre importaciones «importing» y nunca vuelve a crear una fila ya
 * importada, así que repetir el evento no duplica registros.
 */
final class ImportRunner
{
    public function __construct(
        private readonly ImportRegistry $importers,
        private readonly ImportJobStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function run(string $jobId): void
    {
        /** @var ImportJob|null $job */
        $job = ImportJob::query()->whereKey($jobId)->first();
        if ($job === null || $job->status !== 'importing') {
            return;
        }
        $importer = $this->importers->get($job->entity);
        $locale = (string) (DB::table('users')->where('id', $job->created_by_user_id)->value('locale') ?: 'es');
        if ($importer === null) {
            $this->fail($job, (string) trans('imports.entity_invalid', [], $locale), null);

            return;
        }
        $context = new ImportContext($job->tenant_id, $job->id, $job->created_by_user_id, $locale);
        $size = max(1, (int) ($job->batch_size ?? 500));
        $batch = (int) (ImportJobRow::query()->where('import_job_id', $job->id)->max('batch_number') ?? 0);

        while (true) {
            $rows = ImportJobRow::query()->where('import_job_id', $job->id)->where('status', 'valid')
                ->orderBy('row_number')->limit($size)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $batch++;
            $failedRow = null;
            try {
                DB::transaction(function () use ($rows, $importer, $context, $batch, &$failedRow): void {
                    foreach ($rows as $row) {
                        /** @var ImportJobRow $row */
                        $failedRow = $row;
                        $result = $importer->import($row->parsed_data ?? [], $context);
                        $row->forceFill([
                            'status' => 'imported', 'entity_id' => $result['entity_id'],
                            'previous_data' => $result['previous'], 'batch_number' => $batch,
                        ])->save();
                    }
                    $failedRow = null;
                });
            } catch (Throwable $e) {
                $this->rollbackImported($job, $importer, $context);
                $message = $e instanceof ValidationException
                    ? implode(' ', array_map(fn ($m) => implode(' ', (array) $m), $e->errors()))
                    : ($e instanceof AyniException ? $e->getMessage() : (string) trans('imports.failed_unexpected', [], $locale));
                if (! $e instanceof ValidationException && ! $e instanceof AyniException) {
                    Log::error('imports: falla inesperada al importar', ['import_job_id' => $job->id, 'error' => $e->getMessage()]);
                }
                $this->fail($job, $message, $failedRow);

                return;
            }
        }

        DB::transaction(function () use ($job): void {
            $imported = ImportJobRow::query()->where('import_job_id', $job->id)->where('status', 'imported')->count();
            ImportJobRow::query()->where('import_job_id', $job->id)->where('status', 'invalid')->update(['status' => 'skipped']);
            $before = ['status' => $job->status];
            $job->forceFill(['status' => $this->machine->getNextState($job->status, 'finish'), 'imported_rows' => $imported, 'finished_at' => now()])->save();
            $this->outbox->record(DomainEventTypes::IMPORT_COMPLETED, 'import_job', $job->id, [
                'import_job_id' => $job->id, 'entity' => $job->entity, 'imported_rows' => $imported,
            ]);
            $this->audit->record('import.completed', 'import_job', $job->id, $before, ['status' => $job->status, 'imported_rows' => $imported]);
        });
    }

    /** Deshace los lotes ya confirmados de esta importación (en orden inverso). */
    private function rollbackImported(ImportJob $job, Importer $importer, ImportContext $context): void
    {
        DB::transaction(function () use ($job, $importer, $context): void {
            $rows = ImportJobRow::query()->where('import_job_id', $job->id)->where('status', 'imported')->orderByDesc('row_number')->get();
            foreach ($rows as $row) {
                $importer->revert((string) $row->entity_id, $row->previous_data, $context);
                $row->forceFill(['status' => 'valid', 'entity_id' => null, 'previous_data' => null, 'batch_number' => null])->save();
            }
        });
    }

    private function fail(ImportJob $job, string $message, ?ImportJobRow $row): void
    {
        DB::transaction(function () use ($job, $message, $row): void {
            if ($row !== null) {
                $errors = $row->errors ?? [];
                $errors[] = ['column' => null, 'code' => 'import_failed', 'message' => $message];
                ImportJobRow::query()->whereKey($row->id)->update(['status' => 'failed', 'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE)]);
            }
            $summary = $job->summary ?? [];
            $summary['failed_row'] = $row?->row_number;
            $before = ['status' => $job->status];
            $job->forceFill([
                'status' => $this->machine->getNextState($job->status, 'fail'), 'error_message' => $message,
                'imported_rows' => 0, 'finished_at' => now(), 'summary' => $summary,
            ])->save();
            $this->outbox->record(DomainEventTypes::IMPORT_FAILED, 'import_job', $job->id, ['import_job_id' => $job->id, 'entity' => $job->entity]);
            $this->audit->record('import.failed', 'import_job', $job->id, $before, ['status' => $job->status, 'error' => $message, 'row_number' => $row?->row_number]);
        });
    }
}
