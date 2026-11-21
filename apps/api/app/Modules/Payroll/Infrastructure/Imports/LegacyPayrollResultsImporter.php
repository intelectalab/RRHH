<?php

namespace App\Modules\Payroll\Infrastructure\Imports;

use App\Modules\Payroll\Infrastructure\Models\PayrollLegacyResult;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * PER-13 · Resultados del sistema anterior para la planilla en paralelo: por grupo de planilla, periodo,
 * trabajador y concepto, el monto que calculó el sistema que se reemplaza. Los códigos del sistema anterior se
 * traducen con el perfil de origen de IMP-7 (equivalencias de valores) al código del concepto del producto.
 * Volver a importar el mismo trabajador y concepto del periodo reemplaza el monto anterior (que queda en la
 * papelera); deshacer devuelve el anterior. Nunca toca la planilla: solo alimenta la comparación.
 */
final class LegacyPayrollResultsImporter implements Importer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function entity(): string
    {
        return 'legacy_payroll_results';
    }

    public function permission(): string
    {
        return 'payroll.periods.calculate';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('payroll_group', true, 'PLA-EMP', ['grupo', 'grupo_planilla', 'planilla']),
            new ImportColumn('period', true, '2026-05', ['periodo', 'mes']),
            new ImportColumn('document_type', false, 'dni', ['tipo_documento']),
            new ImportColumn('document_number', true, '45678912', ['documento', 'dni']),
            new ImportColumn('concept_code', true, 'sueldo_basico', ['concepto', 'codigo_concepto']),
            new ImportColumn('amount', true, '2500.00', ['monto', 'importe']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $n = trim($data['document_number'] ?? '');

        return $n === '' ? null : implode('@', [mb_strtoupper(trim($data['payroll_group'] ?? '')), trim($data['period'] ?? ''), $n, mb_strtolower(trim($data['concept_code'] ?? ''))]);
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $l = $context->lookups();
        $group = $l->byCode('payroll_groups', $data['payroll_group'] ?? '');
        $entity = $group === null ? null : (string) DB::table('payroll_groups')->where('id', $group)->value('legal_entity_id');
        $period = $group === null ? null : $this->period($group, trim($data['period'] ?? ''));
        $employmentId = $entity === null ? null : $l->employment(($data['document_type'] ?? '') ?: 'dni', $data['document_number'] ?? '', $entity);
        $code = trim($data['concept_code'] ?? '');
        $amount = ImportValues::decimal($data['amount'] ?? '');
        $check = new RowCheck(['payroll_period_id' => $period, 'employment_id' => $employmentId, 'legal_entity_id' => $entity, 'concept_code' => $code, 'amount' => $amount]);
        if ($group === null) {
            $check->error('payroll_group', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['payroll_group'] ?? ''], $context->locale));
        } elseif ($period === null) {
            $check->error('period', 'not_found', (string) trans('payroll.parallel.period_missing', [], $context->locale));
        }
        if ($entity !== null && $employmentId === null) {
            $check->error('document_number', 'not_found', (string) trans('imports.employment_missing', [], $context->locale));
        }
        if ($code === '' || ! DB::table('payroll_concepts')->whereNull('deleted_at')->whereRaw('lower(code) = lower(?)', [$code])->exists()) {
            $check->error('concept_code', 'not_found', (string) trans('payroll.parallel.concept_missing', ['value' => $code], $context->locale));
        }
        if ($amount === null) {
            $check->error('amount', 'invalid', (string) trans('imports.invalid_number', [], $context->locale));
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $code = (string) DB::table('payroll_concepts')->whereNull('deleted_at')->whereRaw('lower(code) = lower(?)', [(string) $parsed['concept_code']])->value('code');
        $currency = (string) (DB::table('legal_entities')->where('id', $parsed['legal_entity_id'])->value('currency') ?? '');
        $previous = PayrollLegacyResult::query()->where('payroll_period_id', $parsed['payroll_period_id'])
            ->where('employment_id', $parsed['employment_id'])->where('concept_code', $code)->first();
        $previous?->delete();
        $row = PayrollLegacyResult::query()->create([
            'payroll_period_id' => (string) $parsed['payroll_period_id'], 'employment_id' => (string) $parsed['employment_id'], 'concept_code' => $code,
            'amount' => (string) BigDecimal::of((string) $parsed['amount'])->toScale(2), 'currency' => $currency, 'import_job_id' => $context->jobId,
        ]);
        $this->audit->record('payroll_legacy_result.imported', 'employment', $row->employment_id, $previous === null ? null : ['id' => $previous->id],
            ['id' => $row->id, 'payroll_period_id' => $row->payroll_period_id, 'concept_code' => $code], 'import:'.$context->jobId);

        return ['entity_id' => (string) $row->id, 'previous' => $previous === null ? ['__created' => true] : ['replaced_id' => $previous->id]];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $row = PayrollLegacyResult::query()->find($entityId);
        if ($row === null) {
            return;
        }
        $row->delete();
        $replaced = is_array($previous) && is_string($previous['replaced_id'] ?? null) ? $previous['replaced_id'] : null;
        if ($replaced !== null) {
            PayrollLegacyResult::query()->onlyTrashed()->whereKey($replaced)->first()?->restore();
        }
        $this->audit->record('payroll_legacy_result.import_reverted', 'employment', $row->employment_id, ['id' => $row->id], $replaced === null ? null : ['id' => $replaced], 'import:'.$context->jobId);
    }

    /** Periodo regular del grupo por «AAAA-MM» o «AAAA-NN» (número de periodo). */
    private function period(string $groupId, string $value): ?string
    {
        if (preg_match('/^(\d{4})[-\/]?(\d{1,2})$/', $value, $m) !== 1) {
            return null;
        }
        $id = DB::table('payroll_periods')->where('payroll_group_id', $groupId)->where('period_year', (int) $m[1])
            ->where('period_number', (int) $m[2])->where('kind', 'regular')->value('id');

        return $id === null ? null : (string) $id;
    }
}
