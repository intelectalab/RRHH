<?php

namespace App\Modules\Organization\Infrastructure\Imports;

use App\Modules\Organization\Application\OrgUnitService;
use App\Modules\Organization\Application\OrgUnitTypeCatalog;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\ImportDryRun;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use Illuminate\Support\Facades\DB;

/**
 * IMP-2 · Importador de la estructura (unidades organizativas). Cada alta pasa por
 * OrgUnitService::create, igual que el alta manual: código único, tipo usable, unidad superior activa
 * en la fecha, empresa y centro de costo existentes. La unidad superior puede venir en una fila
 * anterior del mismo archivo (se resuelve al importar, en orden).
 */
final class OrgUnitsImporter implements Importer
{
    /** Tablas que, si usan la unidad, impiden deshacer (RN-IMP-07). */
    private const DEPENDENTS = ['employment_assignments' => 'org_unit_id', 'positions' => 'org_unit_id', 'org_units' => 'parent_id'];

    public function __construct(
        private readonly OrgUnitService $units,
        private readonly OrgUnitTypeCatalog $types,
        private readonly AuditLogger $audit,
    ) {}

    public function entity(): string
    {
        return 'org_structure';
    }

    public function permission(): string
    {
        return 'org.units.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('code', true, 'ADM-FIN', ['codigo', 'codigo_unidad']),
            new ImportColumn('name', true, 'Finanzas', ['nombre', 'unidad', 'area']),
            new ImportColumn('type', true, 'Área', ['tipo', 'tipo_unidad']),
            new ImportColumn('parent_code', false, 'ADM', ['unidad_superior', 'codigo_superior', 'padre']),
            new ImportColumn('legal_entity', false, '20123456781', ['empresa', 'ruc']),
            new ImportColumn('cost_center_code', false, 'CC-100', ['centro_de_costo', 'centro_costo']),
            new ImportColumn('effective_from', false, '01/01/2024', ['vigente_desde', 'desde', 'fecha']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $code = mb_strtoupper(trim($data['code'] ?? ''));

        return $code === '' ? null : $code;
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $lookups = $context->lookups();
        $this->types->ensureDefaults();
        $code = mb_strtoupper(trim($data['code'] ?? ''));
        $parentCode = mb_strtoupper(trim($data['parent_code'] ?? ''));
        $check = new RowCheck([
            'code' => $code, 'name' => trim($data['name'] ?? ''), 'parent_code' => $parentCode !== '' ? $parentCode : null,
            'type_id' => $lookups->byCodeOrName('org_unit_types', $data['type'] ?? ''),
            'legal_entity_id' => $lookups->legalEntity($data['legal_entity'] ?? ''),
            'effective_from' => null, 'cost_center_id' => null,
        ]);
        if ($check->parsed['type_id'] === null) {
            $check->error('type', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['type'] ?? ''], $context->locale));
        }
        if (($data['legal_entity'] ?? '') !== '' && $check->parsed['legal_entity_id'] === null) {
            $check->error('legal_entity', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['legal_entity']], $context->locale));
        }
        if (($data['cost_center_code'] ?? '') !== '') {
            $check->parsed['cost_center_id'] = $lookups->byCode('cost_centers', $data['cost_center_code'], $check->parsed['legal_entity_id']);
            if ($check->parsed['cost_center_id'] === null) {
                $check->error('cost_center_code', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['cost_center_code']], $context->locale));
            }
        }
        if (($data['effective_from'] ?? '') !== '') {
            $check->parsed['effective_from'] = ImportValues::date($data['effective_from']);
            if ($check->parsed['effective_from'] === null) {
                $check->error('effective_from', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
            }
        }

        // La unidad superior existe ya o viene antes en el archivo (en ese caso se valida sin ella).
        $pendingParent = false;
        if ($parentCode !== '') {
            $check->parsed['parent_id'] = $lookups->byCode('org_units', $parentCode);
            if ($check->parsed['parent_id'] === null) {
                $earlier = $context->memo['org_units'][$parentCode] ?? null;
                if ($earlier === null) {
                    $check->error('parent_code', 'not_found', (string) trans('imports.catalog_missing', ['value' => $parentCode], $context->locale));
                } else {
                    $pendingParent = true;
                    $check->parsed['legal_entity_id'] ??= $earlier;
                }
            }
        }
        if ($check->parsed['legal_entity_id'] === null && $parentCode === '') {
            $check->error('legal_entity', 'required', (string) trans('imports.required', [], $context->locale));
        }

        ImportDryRun::check($check, fn () => $this->units->create($this->input($check->parsed, $pendingParent ? null : ($check->parsed['parent_id'] ?? null)), $context->userId),
            ['code' => 'code', 'name' => 'name', 'type_id' => 'type', 'parent_id' => 'parent_code', 'legal_entity_id' => 'legal_entity', 'cost_center_id' => 'cost_center_code', 'effective_from' => 'effective_from']);
        if ($check->ok()) {
            $context->memo['org_units'][$code] = $check->parsed['legal_entity_id'] ?? DB::table('org_units')->where('id', $check->parsed['parent_id'] ?? '')->value('legal_entity_id');
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $parentId = $parsed['parent_code'] !== null ? $context->lookups()->byCode('org_units', (string) $parsed['parent_code']) : null;
        $unit = $this->units->create($this->input($parsed, $parentId), $context->userId);

        return ['entity_id' => $unit->id, 'previous' => null];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $unit = OrgUnit::query()->whereKey($entityId)->first();
        if ($unit === null) {
            return null;
        }
        foreach (self::DEPENDENTS as $table => $column) {
            if (DB::table($table)->where($column, $entityId)->when(in_array($table, ['positions', 'org_units'], true), fn ($q) => $q->whereNull('deleted_at'))->exists()) {
                return (string) trans('imports.in_use', ['what' => (string) trans("imports.uses.{$table}", [], $context->locale)], $context->locale);
            }
        }
        if ($unit->status !== 'active' || $unit->lock_version > 1) {
            return (string) trans('imports.changed_after_import', [], $context->locale);
        }

        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $unit = OrgUnit::query()->whereKey($entityId)->first();
        if ($unit === null) {
            return;
        }
        $before = $unit->only(['code', 'name', 'parent_id', 'legal_entity_id', 'valid_from']);
        $unit->delete();
        $this->audit->record('org_unit.import_reverted', 'org_unit', $unit->id, $before, null, 'import:'.$context->jobId);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function input(array $parsed, ?string $parentId): array
    {
        return array_filter([
            'code' => $parsed['code'], 'name' => $parsed['name'], 'type_id' => $parsed['type_id'], 'parent_id' => $parentId,
            'legal_entity_id' => $parsed['legal_entity_id'], 'cost_center_id' => $parsed['cost_center_id'],
            'effective_from' => $parsed['effective_from'], 'reason' => 'import',
        ], fn ($v) => $v !== null);
    }
}
