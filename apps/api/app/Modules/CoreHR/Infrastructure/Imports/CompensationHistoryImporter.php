<?php

namespace App\Modules\CoreHR\Infrastructure\Imports;

use App\Modules\CoreHR\Application\CompensationService;
use App\Modules\CoreHR\Application\IdentityDocuments;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * IMP-2 · Importador del histórico de sueldos. Cada fila es un tramo con vigencia en
 * employment_compensations (cierra el sueldo anterior el día previo e inserta el nuevo: nada se
 * sobrescribe). RN-IMP-05: queda como historia «importada» con su acción de personal aplicada, sin
 * pasar por el flujo de aprobación ni emitir eventos. RN-IMP-04: la columna de sueldo exige el permiso
 * por campo. Las filas de una persona van en orden de fecha y después del último tramo existente.
 */
final class CompensationHistoryImporter implements Importer
{
    public function __construct(private readonly EffectiveDated $validity, private readonly AuditLogger $audit) {}

    public function entity(): string
    {
        return 'salaries';
    }

    public function permission(): string
    {
        return 'people.compensations.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('document_type', false, 'dni', ['tipo_documento']),
            new ImportColumn('document_number', true, '45678912', ['documento', 'dni']),
            new ImportColumn('legal_entity', true, '20123456781', ['empresa', 'ruc']),
            new ImportColumn('valid_from', true, '01/01/2025', ['fecha', 'vigente_desde', 'desde']),
            new ImportColumn('base_salary', true, '3800.00', ['sueldo', 'remuneracion'], 'people.employments.salary.edit'),
            new ImportColumn('currency', false, 'PEN', ['moneda']),
            new ImportColumn('pay_frequency', false, 'monthly', ['frecuencia']),
            new ImportColumn('change_reason', false, 'merit', ['motivo']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $number = IdentityDocuments::normalize($data['document_number'] ?? '');

        return $number === '' ? null : $number.'@'.trim($data['valid_from'] ?? '');
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $l = $context->lookups();
        $entity = $l->legalEntity($data['legal_entity'] ?? '');
        $employmentId = $entity === null ? null : $l->employment(($data['document_type'] ?? '') ?: 'dni', $data['document_number'] ?? '', $entity);
        $amount = ImportValues::decimal($data['base_salary'] ?? '');
        $check = new RowCheck([
            'employment_id' => $employmentId, 'valid_from' => ImportValues::date($data['valid_from'] ?? ''), 'amount' => $amount,
            'currency' => strtoupper(trim($data['currency'] ?? '')) ?: (string) DB::table('legal_entities')->where('id', $entity ?? '')->value('currency'),
            'pay_frequency' => trim($data['pay_frequency'] ?? '') ?: 'monthly', 'change_reason' => trim($data['change_reason'] ?? '') ?: 'adjustment',
        ]);
        if ($entity === null) {
            $check->error('legal_entity', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['legal_entity'] ?? ''], $context->locale));
        } elseif ($employmentId === null) {
            $check->error('document_number', 'not_found', (string) trans('imports.employment_missing', [], $context->locale));
        }
        if ($check->parsed['valid_from'] === null) {
            $check->error('valid_from', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
        }
        if ($amount === null || ! BigDecimal::of($amount)->isPositive()) {
            $check->error('base_salary', 'invalid', (string) trans('imports.invalid_number', [], $context->locale));
        }
        if (! DB::table('currencies')->where('code', $check->parsed['currency'])->exists()) {
            $check->error('currency', 'invalid', (string) trans('imports.invalid_value', [], $context->locale));
        }
        if (! in_array($check->parsed['pay_frequency'], CompensationService::FREQUENCIES, true)) {
            $check->error('pay_frequency', 'invalid', (string) trans('imports.invalid_value', [], $context->locale));
        }
        if (! in_array($check->parsed['change_reason'], CompensationService::REASONS, true)) {
            $check->error('change_reason', 'invalid', (string) trans('imports.invalid_value', [], $context->locale));
        }
        if (! $check->ok()) {
            return $check;
        }

        $employment = Employment::query()->findOrFail((string) $employmentId);
        $from = (string) $check->parsed['valid_from'];
        if ($from < $employment->hire_date->toDateString() || ($employment->termination_date !== null && $from > $employment->termination_date->toDateString())) {
            $check->error('valid_from', 'out_of_employment', (string) trans('imports.outside_employment', [], $context->locale));
        }
        // Después del último tramo: el de la base o el de una fila anterior del mismo archivo.
        $last = $context->memo['compensation_history.'.$employmentId]
            ?? DB::table('employment_compensations')->where('employment_id', $employmentId)->max('valid_from');
        if (is_string($last) && $from <= EffectiveDated::day($last)) {
            $check->error('valid_from', 'order', (string) trans('imports.history_order', [], $context->locale));
        } elseif ($check->errors === []) {
            $context->memo['compensation_history.'.$employmentId] = $from;
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $tenant = TenancyScope::requireTenant();
        $employmentId = (string) $parsed['employment_id'];
        $from = (string) $parsed['valid_from'];

        return DB::transaction(function () use ($tenant, $employmentId, $from, $parsed, $context): array {
            $relation = ['tenant_id' => $tenant, 'employment_id' => $employmentId];
            $closed = $this->validity->rowAt('employment_compensations', $relation, $from);
            $money = ['amount' => (string) $parsed['amount'], 'currency' => (string) $parsed['currency']];
            $actionId = (string) DB::table('personnel_actions')->insertGetId([
                'tenant_id' => $tenant, 'employment_id' => $employmentId, 'kind' => 'salary_change', 'effective_on' => $from,
                'reason' => (string) $parsed['change_reason'], 'status' => 'applied', 'requested_by_user_id' => $context->userId, 'applied_at' => now(),
                'payload' => json_encode(['base_salary' => $money, 'pay_frequency' => $parsed['pay_frequency'], 'imported' => true, 'import_job_id' => $context->jobId], JSON_THROW_ON_ERROR),
                'changes' => json_encode(['base_salary' => $money], JSON_THROW_ON_ERROR),
            ]);
            $id = $this->validity->change('employment_compensations', $relation, [
                'base_salary' => $money['amount'], 'currency' => $money['currency'], 'pay_frequency' => $parsed['pay_frequency'],
                'change_reason' => $parsed['change_reason'], 'personnel_action_id' => $actionId,
            ], $from);
            // El sueldo es dato sensible: la auditoría guarda que se importó, no el monto.
            $this->audit->record('compensation.imported', 'employment', $employmentId, null, ['valid_from' => $from, 'reason' => $parsed['change_reason']], 'import:'.$context->jobId);

            return ['entity_id' => $id, 'previous' => [
                '__created' => true, 'action_id' => $actionId,
                'closed_id' => $closed === null ? null : (string) $closed->id,
                'closed_valid_to' => $closed === null || $closed->valid_to === null ? null : (string) $closed->valid_to,
            ]];
        });
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $row = DB::table('employment_compensations')->where('id', $entityId)->first();
        if ($row === null) {
            return null;
        }
        if (DB::table('employment_compensations')->where('employment_id', $row->employment_id)->where('valid_from', '>', $row->valid_from)
            ->whereNotIn('id', DB::table('import_job_rows')->where('import_job_id', $context->jobId)->whereNotNull('entity_id')->select('entity_id'))->exists()) {
            return (string) trans('imports.changed_after_import', [], $context->locale);
        }

        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $row = DB::table('employment_compensations')->where('id', $entityId)->first();
        if ($row === null || $previous === null) {
            return;
        }
        // Regla 2: el tramo importado va a la papelera (no se borra) antes de reabrir el anterior.
        DB::select('SELECT trash_labor_history_row(?, ?)', ['employment_compensations', $entityId]);
        if (is_string($previous['closed_id'] ?? null)) {
            DB::table('employment_compensations')->where('id', $previous['closed_id'])->update(['valid_to' => $previous['closed_valid_to']]);
        }
        DB::table('personnel_actions')->where('id', (string) $previous['action_id'])->update(['deleted_at' => now()]);
        $this->audit->record('compensation.import_reverted', 'employment', (string) $row->employment_id, ['valid_from' => $row->valid_from], null, 'import:'.$context->jobId);
    }
}
