<?php

namespace App\Modules\Payroll\Infrastructure\Imports;

use App\Modules\Payroll\Application\FormulaValidator;
use App\Modules\Payroll\Application\PayrollConcepts;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use Illuminate\Support\Facades\DB;

/**
 * CPT-6 · Conceptos propios de la organización traídos desde otro sistema.
 *
 * Cada fila entra como concepto propio en borrador por la misma vía que el alta manual (PayrollConcepts::create):
 * código único, naturaleza válida, código de la Tabla 22 vigente (CPT-7) y fórmula y condición que el motor
 * entiende (NOM-3). No se publica nada: el borrador se revisa y se publica en Nómina → Conceptos (RN-CPT-01).
 * Deshacer retira el concepto a la papelera mientras no tenga una versión publicada ni incidencias.
 */
final class PayrollConceptsImporter implements Importer
{
    private const KINDS = ['earning', 'deduction', 'employee_contribution', 'employer_contribution', 'tax', 'benefit', 'provision', 'informative'];

    public function __construct(private readonly PayrollConcepts $concepts, private readonly FormulaValidator $validator) {}

    public function entity(): string
    {
        return 'concepts';
    }

    public function permission(): string
    {
        return 'payroll.concepts.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('code', true, 'bono_campana', ['codigo', 'cod_concepto']),
            new ImportColumn('name', true, 'Bono de campaña', ['nombre', 'descripcion', 'concepto']),
            new ImportColumn('kind', true, 'earning', ['tipo', 'naturaleza']),
            new ImportColumn('statutory_code', false, '0902', ['codigo_sunat', 'codigo_plame', 'tabla_22']),
            new ImportColumn('is_remunerative', false, 'si', ['remunerativo']),
            new ImportColumn('formula', false, '', ['formula']),
            new ImportColumn('condition', false, '', ['condicion']),
            new ImportColumn('category', false, '', ['categoria']),
            new ImportColumn('print_on_payslip', false, 'si', ['imprime_boleta', 'en_boleta']),
            new ImportColumn('notes', false, '', ['nota', 'observacion']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $code = trim($data['code'] ?? '');

        return $code === '' ? null : mb_strtolower($code);
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $code = trim($data['code'] ?? '');
        $kind = trim($data['kind'] ?? '');
        $statutory = trim($data['statutory_code'] ?? '');
        $formula = trim($data['formula'] ?? '');
        $condition = trim($data['condition'] ?? '');
        $remunerative = trim($data['is_remunerative'] ?? '');
        $payslip = trim($data['print_on_payslip'] ?? '');
        $check = new RowCheck([
            'code' => $code, 'name' => trim($data['name'] ?? ''), 'kind' => $kind, 'statutory_code' => $statutory === '' ? null : $statutory,
            'formula' => $formula === '' ? null : $formula, 'condition' => $condition === '' ? null : $condition,
            'category' => trim($data['category'] ?? '') ?: null, 'notes' => trim($data['notes'] ?? '') ?: null,
            'is_remunerative' => $remunerative === '' ? true : ImportValues::bool($remunerative),
            'print_on_payslip' => $payslip === '' ? true : ImportValues::bool($payslip),
        ]);
        if (preg_match('/^[a-z][a-z0-9_]{1,59}$/', $code) !== 1) {
            $check->error('code', 'invalid', (string) trans('payroll.import.code_format', [], $context->locale));
        } elseif (DB::table('payroll_concepts')->where('code', $code)->exists()) {
            $check->error('code', 'duplicate', (string) trans('payroll.concepts.code_taken', ['code' => $code], $context->locale));
        }
        if (! in_array($kind, self::KINDS, true)) {
            $check->error('kind', 'invalid', (string) trans('payroll.import.kind_invalid', ['options' => implode(', ', self::KINDS)], $context->locale));
        }
        foreach (['is_remunerative', 'print_on_payslip'] as $flag) {
            if ($check->parsed[$flag] === null) {
                $check->error($flag, 'invalid', (string) trans('imports.invalid_bool', [], $context->locale));
            }
        }
        if ($statutory !== '' && ! $this->concepts->statutoryCodeExists($statutory, now()->toDateString())) {
            $check->error('statutory_code', 'not_found', (string) trans('payroll.concepts.statutory_unknown', ['code' => $statutory], $context->locale));
        }
        if ($check->ok() && ($formula !== '' || $condition !== '')) {
            $r = $this->validator->validate($formula === '' ? '0' : $formula, $code, now()->toDateString(), $condition === '' ? null : $condition, []);
            foreach ($r['errors'] as $e) {
                $check->error(($e['part'] ?? 'formula') === 'condition' ? 'condition' : 'formula', (string) ($e['code'] ?? 'invalid'), (string) ($e['message'] ?? ''));
            }
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $created = $this->concepts->create([
            'code' => $parsed['code'], 'name' => $parsed['name'], 'kind' => $parsed['kind'], 'statutory_code' => $parsed['statutory_code'],
            'is_remunerative' => (bool) $parsed['is_remunerative'], 'print_on_payslip' => (bool) $parsed['print_on_payslip'],
            'formula' => $parsed['formula'], 'condition' => $parsed['condition'], 'category' => $parsed['category'],
            'notes' => $parsed['notes'] ?? trans('payroll.import.concept_note', ['job' => $context->jobId]),
        ], $context->userId);

        return ['entity_id' => (string) $created['id'], 'previous' => ['__created' => true]];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $published = DB::table('payroll_concept_versions')->where('payroll_concept_id', $entityId)->exists();
        $used = DB::table('payroll_incidents')->where('payroll_concept_id', $entityId)->whereNull('deleted_at')->exists();

        return $published || $used ? (string) trans('imports.changed_after_import', [], $context->locale) : null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        // Borrado lógico: el concepto queda en la papelera con su auditoría.
        DB::table('payroll_concepts')->where('id', $entityId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
    }
}
