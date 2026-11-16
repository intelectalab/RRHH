<?php

namespace App\Modules\Organization\Infrastructure\Imports;

use App\Modules\Organization\Application\PositionService;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\ImportDryRun;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use Illuminate\Support\Facades\DB;

/**
 * IMP-2 · Importador de posiciones (plazas). Cada alta pasa por PositionService::create (código único,
 * puesto, unidad, empresa y sede usables, vigencia) y, si quien importa puede aprobar posiciones, se
 * aprueba con la misma acción del alta manual (presupuesto incluido); si no, queda propuesta. La
 * posición jefe puede venir en una fila anterior del mismo archivo.
 */
final class PositionsImporter implements Importer
{
    public function __construct(private readonly PositionService $positions, private readonly AuditLogger $audit) {}

    public function entity(): string
    {
        return 'positions';
    }

    public function permission(): string
    {
        return 'org.positions.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('code', true, 'POS-0101', ['codigo', 'codigo_posicion', 'plaza']),
            new ImportColumn('title', false, 'Analista contable senior', ['nombre', 'titulo', 'denominacion']),
            new ImportColumn('legal_entity', true, '20123456781', ['empresa', 'ruc']),
            new ImportColumn('job_profile_code', true, 'ANL-CONT', ['puesto', 'codigo_puesto', 'cargo']),
            new ImportColumn('org_unit_code', true, 'ADM-FIN', ['unidad', 'codigo_unidad', 'area']),
            new ImportColumn('site', false, 'Sede central', ['sede', 'local']),
            new ImportColumn('cost_center_code', false, 'CC-100', ['centro_de_costo', 'centro_costo']),
            new ImportColumn('reports_to_code', false, 'POS-0100', ['reporta_a', 'posicion_jefe']),
            new ImportColumn('seats', false, '1', ['vacantes', 'cupos', 'plazas']),
            new ImportColumn('valid_from', false, '01/01/2024', ['vigente_desde', 'desde']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $code = mb_strtoupper(trim($data['code'] ?? ''));

        return $code === '' ? null : $code;
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $l = $context->lookups();
        $entity = $l->legalEntity($data['legal_entity'] ?? '');
        $code = mb_strtoupper(trim($data['code'] ?? ''));
        $reportsTo = mb_strtoupper(trim($data['reports_to_code'] ?? ''));
        $check = new RowCheck([
            'code' => $code, 'title' => trim($data['title'] ?? ''), 'legal_entity_id' => $entity,
            'job_profile_id' => $l->byCode('job_profiles', $data['job_profile_code'] ?? ''),
            'org_unit_id' => $l->byCode('org_units', $data['org_unit_code'] ?? ''),
            'reports_to_code' => $reportsTo !== '' ? $reportsTo : null,
        ]);
        $refs = ['legal_entity' => $entity, 'job_profile_code' => $check->parsed['job_profile_id'], 'org_unit_code' => $check->parsed['org_unit_id']];
        if (($data['site'] ?? '') !== '') {
            $refs['site'] = $check->parsed['site_id'] = $l->byCodeOrName('sites', $data['site'], $entity);
        }
        if (($data['cost_center_code'] ?? '') !== '') {
            $refs['cost_center_code'] = $check->parsed['cost_center_id'] = $l->byCode('cost_centers', $data['cost_center_code'], $entity);
        }
        foreach ($refs as $column => $id) {
            if ($id === null) {
                $check->error($column, 'not_found', (string) trans('imports.catalog_missing', ['value' => $data[$column] ?? ''], $context->locale));
            }
        }
        if ($check->parsed['title'] === '') {
            $check->parsed['title'] = (string) DB::table('job_profiles')->where('id', $check->parsed['job_profile_id'] ?? '')->value('name');
        }
        if (($data['seats'] ?? '') !== '') {
            $seats = filter_var($data['seats'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($seats === false) {
                $check->error('seats', 'invalid', (string) trans('imports.invalid_number', [], $context->locale));
            } else {
                $check->parsed['seats'] = $seats;
            }
        }
        if (($data['valid_from'] ?? '') !== '') {
            $check->parsed['valid_from'] = ImportValues::date($data['valid_from']);
            if ($check->parsed['valid_from'] === null) {
                $check->error('valid_from', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
            }
        }
        $pendingBoss = false;
        if ($reportsTo !== '') {
            $check->parsed['reports_to_position_id'] = $l->byCode('positions', $reportsTo);
            if ($check->parsed['reports_to_position_id'] === null) {
                if (isset($context->memo['positions'][$reportsTo])) {
                    $pendingBoss = true;
                } else {
                    $check->error('reports_to_code', 'not_found', (string) trans('imports.catalog_missing', ['value' => $reportsTo], $context->locale));
                }
            }
        }
        if ($check->ok() && ! $context->can('org.positions.approve')) {
            $check->warning(null, 'stays_proposed', (string) trans('imports.positions_proposed', [], $context->locale));
        }

        ImportDryRun::check($check, fn () => $this->create($check->parsed, $pendingBoss ? null : ($check->parsed['reports_to_position_id'] ?? null), $context), [
            'code' => 'code', 'title' => 'title', 'legal_entity_id' => 'legal_entity', 'job_profile_id' => 'job_profile_code', 'org_unit_id' => 'org_unit_code',
            'site_id' => 'site', 'cost_center_id' => 'cost_center_code', 'reports_to_position_id' => 'reports_to_code', 'seats' => 'seats', 'valid_from' => 'valid_from',
        ]);
        if ($check->ok()) {
            $context->memo['positions'][$code] = true;
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $boss = $parsed['reports_to_code'] !== null ? $context->lookups()->byCode('positions', (string) $parsed['reports_to_code']) : null;

        return ['entity_id' => $this->create($parsed, $boss, $context)->id, 'previous' => null];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $position = Position::query()->whereKey($entityId)->first();
        if ($position === null) {
            return null;
        }
        if (DB::table('employment_assignments')->where('position_id', $entityId)->exists()) {
            return (string) trans('imports.in_use', ['what' => (string) trans('imports.uses.employment_assignments', [], $context->locale)], $context->locale);
        }
        if (Position::query()->where('reports_to_position_id', $entityId)->exists() || DB::table('org_units')->where('head_position_id', $entityId)->whereNull('deleted_at')->exists()) {
            return (string) trans('imports.in_use', ['what' => (string) trans('imports.uses.positions', [], $context->locale)], $context->locale);
        }
        // Lo que hizo la importación son hasta dos eventos (crear y aprobar); otro cambio la deja en uso.
        if (! in_array($position->status, ['proposed', 'approved'], true) || DB::table('position_status_events')->where('position_id', $entityId)->count() > 2) {
            return (string) trans('imports.changed_after_import', [], $context->locale);
        }

        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $position = Position::query()->whereKey($entityId)->first();
        if ($position === null) {
            return;
        }
        $before = $position->only(['code', 'title', 'status', 'org_unit_id', 'job_profile_id']);
        $position->delete();
        $this->audit->record('position.import_reverted', 'position', $position->id, $before, null, 'import:'.$context->jobId);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function create(array $parsed, ?string $reportsTo, ImportContext $context): Position
    {
        $input = array_intersect_key($parsed, PositionService::FIELDS);
        $input['reports_to_position_id'] = $reportsTo;
        $position = $this->positions->create(array_filter($input, fn ($v) => $v !== null), $context->userId);
        if ($context->can('org.positions.approve')) {
            $position = $this->positions->transition($position, 'approve', (string) trans('imports.approved_by_import', [], $context->locale), null, $context->userId);
        }

        return $position;
    }
}
