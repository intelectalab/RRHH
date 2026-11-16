<?php

namespace App\Modules\CoreHR\Infrastructure\Imports;

use App\Modules\CoreHR\Application\AssignmentMovements;
use App\Modules\CoreHR\Application\IdentityDocuments;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\ImportDryRun;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use App\Shared\Organization\PositionOccupancy;
use Illuminate\Support\Facades\DB;

/**
 * IMP-2 · Importador del histórico de movimientos (promociones, traslados, cambios de jefe o de centro
 * de costo). Cada fila es un tramo con vigencia: cierra la asignación del día anterior y abre otra
 * (HIS-2), con las mismas validaciones que el movimiento manual. RN-IMP-05: queda como historia
 * «importada», sin aprobaciones, eventos ni notificaciones. Las filas de una persona van en orden de fecha.
 */
final class AssignmentHistoryImporter implements Importer
{
    public function __construct(
        private readonly AssignmentMovements $movements,
        private readonly PositionOccupancy $occupancy,
        private readonly AuditLogger $audit,
    ) {}

    public function entity(): string
    {
        return 'history';
    }

    public function permission(): string
    {
        return 'people.assignments.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('document_type', false, 'dni', ['tipo_documento']),
            new ImportColumn('document_number', true, '45678912', ['documento', 'dni']),
            new ImportColumn('legal_entity', true, '20123456781', ['empresa', 'ruc']),
            new ImportColumn('effective_on', true, '01/03/2025', ['fecha', 'vigente_desde', 'desde']),
            new ImportColumn('kind', false, 'promotion', ['tipo', 'tipo_movimiento']),
            new ImportColumn('position_code', false, 'POS-0102', ['posicion', 'plaza']),
            new ImportColumn('job_profile_code', false, 'JEF-CONT', ['puesto', 'cargo']),
            new ImportColumn('org_unit_code', false, 'ADM-FIN', ['unidad', 'area']),
            new ImportColumn('site', false, 'Sede central', ['sede']),
            new ImportColumn('cost_center_code', false, 'CC-100', ['centro_de_costo']),
            new ImportColumn('manager_document', false, '41234567', ['jefe', 'dni_jefe']),
            new ImportColumn('reason', false, 'Promoción por desempeño', ['motivo']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $number = IdentityDocuments::normalize($data['document_number'] ?? '');

        return $number === '' ? null : $number.'@'.trim($data['effective_on'] ?? '');
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $l = $context->lookups();
        $entity = $l->legalEntity($data['legal_entity'] ?? '');
        $employmentId = $entity === null ? null : $l->employment(($data['document_type'] ?? '') ?: 'dni', $data['document_number'] ?? '', $entity);
        $check = new RowCheck(['employment_id' => $employmentId, 'effective_on' => ImportValues::date($data['effective_on'] ?? ''), 'changes' => [],
            'kind' => trim($data['kind'] ?? '') ?: null, 'reason' => trim($data['reason'] ?? '') ?: null]);
        if ($entity === null) {
            $check->error('legal_entity', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['legal_entity'] ?? ''], $context->locale));
        } elseif ($employmentId === null) {
            $check->error('document_number', 'not_found', (string) trans('imports.employment_missing', [], $context->locale));
        }
        if ($check->parsed['effective_on'] === null) {
            $check->error('effective_on', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
        }
        if ($check->parsed['kind'] !== null && ! in_array($check->parsed['kind'], AssignmentMovements::KINDS, true)) {
            $check->error('kind', 'invalid', (string) trans('imports.invalid_value', [], $context->locale));
        }
        foreach (['position_code' => ['positions', 'position_id'], 'job_profile_code' => ['job_profiles', 'job_profile_id'],
            'org_unit_code' => ['org_units', 'org_unit_id'], 'cost_center_code' => ['cost_centers', 'cost_center_id']] as $column => [$table, $key]) {
            if (($data[$column] ?? '') !== '') {
                $this->ref($check, $column, $key, $l->byCode($table, $data[$column], $entity), $data, $context);
            }
        }
        if (($data['site'] ?? '') !== '') {
            $this->ref($check, 'site', 'site_id', $l->byCodeOrName('sites', $data['site'], $entity), $data, $context);
        }
        if (($data['manager_document'] ?? '') !== '' && $entity !== null) {
            $this->ref($check, 'manager_document', 'manager_employment_id', $l->employment('dni', $data['manager_document'], $entity), $data, $context);
        }
        if ($check->ok() && $check->parsed['changes'] === []) {
            $check->error(null, 'no_changes', (string) trans('imports.no_changes', [], $context->locale));
        }
        // Una fila anterior de la misma persona en el archivo todavía no está en la base: se valida su orden.
        $key = 'assignment_history.'.($employmentId ?? '');
        $last = $context->memo[$key] ?? null;
        if ($check->ok() && is_string($last)) {
            if ($check->parsed['effective_on'] <= $last) {
                $check->error('effective_on', 'order', (string) trans('imports.history_order', [], $context->locale));
            }
        } elseif ($check->ok()) {
            ImportDryRun::check($check, fn () => $this->movements->preview($this->employment($check->parsed), $check->parsed['changes'], (string) $check->parsed['effective_on'], $check->parsed['kind']),
                ['valid_from' => 'effective_on', 'job_profile_id' => 'job_profile_code', 'position_id' => 'position_code', 'org_unit_id' => 'org_unit_code',
                    'site_id' => 'site', 'cost_center_id' => 'cost_center_code', 'manager_employment_id' => 'manager_document']);
        }
        if ($check->ok()) {
            $context->memo[$key] = $check->parsed['effective_on'];
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $result = $this->movements->applyImported($this->employment($parsed), (array) $parsed['changes'], (string) $parsed['effective_on'],
            $parsed['kind'], $parsed['reason'], $context->userId, $context->jobId);

        return ['entity_id' => $result['assignment_id'], 'previous' => ['__created' => true] + $result];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $row = DB::table('employment_assignments')->where('id', $entityId)->first();
        if ($row === null) {
            return null;
        }
        if (DB::table('employment_assignments')->where('employment_id', $row->employment_id)->where('valid_from', '>', $row->valid_from)
            ->whereNotIn('id', DB::table('import_job_rows')->where('import_job_id', $context->jobId)->whereNotNull('entity_id')->select('entity_id'))->exists()) {
            return (string) trans('imports.changed_after_import', [], $context->locale);
        }

        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $row = DB::table('employment_assignments')->where('id', $entityId)->first();
        if ($row === null || $previous === null) {
            return;
        }
        // Regla 2: el tramo importado va a la papelera (no se borra) antes de reabrir el anterior.
        DB::select('SELECT trash_labor_history_row(?, ?)', ['employment_assignments', $entityId]);
        DB::table('employment_assignments')->where('id', (string) $previous['closed_id'])->update(['valid_to' => $previous['closed_valid_to']]);
        DB::table('personnel_actions')->where('id', (string) $previous['action_id'])->update(['deleted_at' => now()]);
        $closedPosition = DB::table('employment_assignments')->where('id', (string) $previous['closed_id'])->value('position_id');
        foreach (array_unique(array_filter([$row->position_id, $closedPosition])) as $positionId) {
            $this->occupancy->sync((string) $positionId, $context->userId);
        }
        $this->audit->record('employment.assignment_import_reverted', 'employment', (string) $row->employment_id, ['valid_from' => $row->valid_from], null, 'import:'.$context->jobId);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function ref(RowCheck $check, string $column, string $key, ?string $id, array $data, ImportContext $context): void
    {
        if ($id === null) {
            $check->error($column, 'not_found', (string) trans('imports.catalog_missing', ['value' => $data[$column] ?? ''], $context->locale));

            return;
        }
        $check->parsed['changes'][$key] = $id;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function employment(array $parsed): Employment
    {
        return Employment::query()->findOrFail((string) $parsed['employment_id']);
    }
}
