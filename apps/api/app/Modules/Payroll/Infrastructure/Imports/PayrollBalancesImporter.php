<?php

namespace App\Modules\Payroll\Infrastructure\Imports;

use App\Modules\Payroll\Infrastructure\Models\PayrollOpeningBalance;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * PER-12 · Acumulados del ejercicio de una organización que empieza a usar el producto a mitad de año: por
 * trabajador, ingresos y retenciones de quinta, aportes previsionales y de salud, bases de gratificación y CTS
 * del semestre en curso y depósitos de CTS previos. Cada fila es un saldo de apertura con fecha de corte y
 * sustento (nunca una boleta). Deshacer lo lleva a la papelera mientras ningún periodo cerrado del ejercicio
 * haya usado el acumulado.
 */
final class PayrollBalancesImporter implements Importer
{
    /** Tipos que dependen del semestre (bases de gratificación y CTS, depósitos). */
    private const SEMESTER_KINDS = ['gratification_base', 'cts_base', 'cts_deposit'];

    /** Estados de periodo que ya no se reabren para un acumulado (RN-PER-08). */
    private const CLOSED = ['closed', 'paid', 'accounted'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function entity(): string
    {
        return 'payroll_balances';
    }

    public function permission(): string
    {
        return 'payroll.incidents.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('document_type', false, 'dni', ['tipo_documento']),
            new ImportColumn('document_number', true, '45678912', ['documento', 'dni']),
            new ImportColumn('legal_entity', true, '20123456781', ['empresa', 'ruc']),
            new ImportColumn('fiscal_year', true, '2026', ['ejercicio', 'anio', 'año']),
            new ImportColumn('cutoff_date', true, '30/06/2026', ['fecha_corte', 'corte']),
            new ImportColumn('kind', true, 'fifth_income', ['tipo', 'acumulado']),
            new ImportColumn('amount', true, '18500.00', ['monto', 'importe']),
            new ImportColumn('period_month', false, '6', ['mes']),
            new ImportColumn('semester', false, '1', ['semestre']),
            new ImportColumn('concept_code', false, 'afp_aporte', ['concepto']),
            new ImportColumn('evidence', true, 'Reporte de acumulados del sistema anterior al 30/06', ['sustento', 'fuente']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $n = trim($data['document_number'] ?? '');

        return $n === '' ? null : implode('@', [$n, trim($data['legal_entity'] ?? ''), trim($data['fiscal_year'] ?? ''), trim($data['kind'] ?? ''),
            trim($data['period_month'] ?? ''), trim($data['semester'] ?? ''), trim($data['concept_code'] ?? '')]);
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $l = $context->lookups();
        $entity = $l->legalEntity($data['legal_entity'] ?? '');
        $employmentId = $entity === null ? null : $l->employment(($data['document_type'] ?? '') ?: 'dni', $data['document_number'] ?? '', $entity);
        $year = self::int($data['fiscal_year'] ?? '');
        $month = self::int($data['period_month'] ?? '');
        $semester = self::int($data['semester'] ?? '');
        $kind = strtolower(trim($data['kind'] ?? ''));
        $amount = ImportValues::decimal($data['amount'] ?? '');
        $check = new RowCheck([
            'employment_id' => $employmentId, 'legal_entity_id' => $entity, 'fiscal_year' => $year, 'cutoff_date' => ImportValues::date($data['cutoff_date'] ?? ''),
            'kind' => $kind, 'amount' => $amount, 'period_month' => $month, 'semester' => $semester,
            'concept_code' => trim($data['concept_code'] ?? '') ?: null, 'evidence' => trim($data['evidence'] ?? ''),
        ]);
        if ($entity === null) {
            $check->error('legal_entity', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['legal_entity'] ?? ''], $context->locale));
        } elseif ($employmentId === null) {
            $check->error('document_number', 'not_found', (string) trans('imports.employment_missing', [], $context->locale));
        }
        if ($year === null || $year < 2000 || $year > 2100) {
            $check->error('fiscal_year', 'invalid', (string) trans('imports.invalid_number', [], $context->locale));
        }
        if ($check->parsed['cutoff_date'] === null) {
            $check->error('cutoff_date', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
        } elseif ($year !== null && (int) substr((string) $check->parsed['cutoff_date'], 0, 4) !== $year) {
            $check->error('cutoff_date', 'year_mismatch', (string) trans('payroll.balances.cutoff_year', [], $context->locale));
        }
        if (! in_array($kind, PayrollOpeningBalance::KINDS, true)) {
            $check->error('kind', 'invalid', (string) trans('payroll.balances.kind_invalid', ['kinds' => implode(', ', PayrollOpeningBalance::KINDS)], $context->locale));
        }
        if ($amount === null || BigDecimal::of($amount)->isNegative()) {
            $check->error('amount', 'invalid', (string) trans('imports.invalid_number', [], $context->locale));
        }
        if (trim($data['period_month'] ?? '') !== '' && ($month === null || $month < 1 || $month > 12)) {
            $check->error('period_month', 'invalid', (string) trans('payroll.balances.month_invalid', [], $context->locale));
        }
        if (in_array($kind, self::SEMESTER_KINDS, true) && ! in_array($semester, [1, 2], true)) {
            $check->error('semester', 'required', (string) trans('payroll.balances.semester_required', [], $context->locale));
        }
        if ($check->parsed['evidence'] === '') {
            $check->error('evidence', 'required', (string) trans('payroll.balances.evidence_required', [], $context->locale));
        }
        if ($check->ok() && $this->usedByClosedPeriod((string) $employmentId, (int) $year)) {
            // Un periodo cerrado del ejercicio ya usó lo que había: el acumulado no puede cambiar lo declarado (ADR 0017).
            $check->error('fiscal_year', 'period_closed', (string) trans('payroll.balances.closed_period', [], $context->locale));
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $employmentId = (string) $parsed['employment_id'];
        $currency = (string) (DB::table('legal_entities')->where('id', $parsed['legal_entity_id'])->value('currency') ?? '');
        $row = PayrollOpeningBalance::query()->create([
            'employment_id' => $employmentId, 'fiscal_year' => (int) $parsed['fiscal_year'], 'cutoff_date' => (string) $parsed['cutoff_date'],
            'kind' => (string) $parsed['kind'], 'amount' => (string) BigDecimal::of((string) $parsed['amount'])->toScale(2),
            'period_month' => $parsed['period_month'], 'semester' => $parsed['semester'], 'concept_code' => $parsed['concept_code'],
            'currency' => $currency, 'evidence' => (string) $parsed['evidence'], 'import_job_id' => $context->jobId, 'created_by_user_id' => $context->userId,
        ]);
        $this->audit->record('payroll_opening_balance.imported', 'employment', $employmentId, null,
            ['id' => $row->id, 'fiscal_year' => $row->fiscal_year, 'kind' => $row->kind, 'cutoff_date' => (string) $parsed['cutoff_date']], 'import:'.$context->jobId);

        return ['entity_id' => (string) $row->id, 'previous' => ['__created' => true]];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $row = PayrollOpeningBalance::query()->find($entityId);
        if ($row === null) {
            return null;
        }

        return $this->usedByClosedPeriod($row->employment_id, $row->fiscal_year)
            ? (string) trans('payroll.balances.closed_period', [], $context->locale) : null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $row = PayrollOpeningBalance::query()->find($entityId);
        if ($row === null) {
            return;
        }
        // Dato laboral: a la papelera, nunca borrado físico.
        $row->delete();
        $this->audit->record('payroll_opening_balance.import_reverted', 'employment', $row->employment_id, ['id' => $row->id], null, 'import:'.$context->jobId);
    }

    /** ¿Algún periodo cerrado del ejercicio ya calculó a este trabajador? */
    private function usedByClosedPeriod(string $employmentId, int $year): bool
    {
        return DB::table('payroll_run_employments AS re')
            ->join('payroll_runs AS r', 'r.id', '=', 're.payroll_run_id')
            ->join('payroll_periods AS p', 'p.id', '=', 'r.payroll_period_id')
            ->where('re.employment_id', $employmentId)->whereIn('p.status', self::CLOSED)
            ->whereRaw('EXTRACT(YEAR FROM COALESCE(p.payment_date, p.ends_on)) = ?', [$year])->exists();
    }

    private static function int(string $value): ?int
    {
        $v = trim($value);

        return preg_match('/^\d{1,4}$/', $v) === 1 ? (int) $v : null;
    }
}
