<?php

namespace App\Modules\Vacations\Infrastructure\Imports;

use App\Modules\Vacations\Application\VacationAccrual;
use App\Modules\Vacations\Domain\LedgerEntryType;
use App\Modules\Vacations\Infrastructure\Models\VacationAccrualPeriod;
use App\Modules\Vacations\Infrastructure\Models\VacationLedgerEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use Brick\Math\BigDecimal;

/**
 * VAC-7 · Saldos iniciales de vacaciones por periodo vacacional (RN-VAC-02: el saldo es la suma del libro).
 *
 * Cada fila dice cuántos días le quedan a un trabajador de un periodo ya ganado según el sistema anterior. Se
 * registra un movimiento de apertura (opening_balance) con su fecha que lleva el disponible del periodo a ese
 * valor; el libro no se edita. Valida que el trabajador y el periodo existan y que el periodo no tenga ya un
 * saldo inicial. Deshacer agrega el contraasiento (el libro es de solo inserción).
 */
final class VacationBalancesImporter implements Importer
{
    public function __construct(private readonly VacationAccrual $accrual, private readonly AuditLogger $audit) {}

    public function entity(): string
    {
        return 'vacation_balances';
    }

    public function permission(): string
    {
        return 'time.vacation_balances.adjust';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('document_type', false, 'dni', ['tipo_documento']),
            new ImportColumn('document_number', true, '45678912', ['documento', 'dni']),
            new ImportColumn('legal_entity', true, '20123456781', ['empresa', 'ruc']),
            new ImportColumn('period_start', true, '01/03/2024', ['inicio_periodo', 'periodo', 'periodo_desde']),
            new ImportColumn('available_days', true, '12.5', ['saldo', 'dias', 'dias_pendientes']),
            new ImportColumn('effective_on', false, '31/12/2025', ['fecha', 'fecha_saldo']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $n = trim($data['document_number'] ?? '');

        return $n === '' ? null : $n.'@'.trim($data['period_start'] ?? '');
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $l = $context->lookups();
        $entity = $l->legalEntity($data['legal_entity'] ?? '');
        $employmentId = $entity === null ? null : $l->employment(($data['document_type'] ?? '') ?: 'dni', $data['document_number'] ?? '', $entity);
        $days = ImportValues::decimal($data['available_days'] ?? '');
        $effective = trim($data['effective_on'] ?? '');
        $check = new RowCheck([
            'employment_id' => $employmentId, 'period_start' => ImportValues::date($data['period_start'] ?? ''), 'days' => $days,
            'effective_on' => $effective === '' ? now()->toDateString() : ImportValues::date($effective),
        ]);
        if ($entity === null) {
            $check->error('legal_entity', 'not_found', (string) trans('imports.catalog_missing', ['value' => $data['legal_entity'] ?? ''], $context->locale));
        } elseif ($employmentId === null) {
            $check->error('document_number', 'not_found', (string) trans('imports.employment_missing', [], $context->locale));
        }
        if ($check->parsed['period_start'] === null) {
            $check->error('period_start', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
        }
        if ($check->parsed['effective_on'] === null) {
            $check->error('effective_on', 'invalid', (string) trans('imports.invalid_date', [], $context->locale));
        }
        if ($days === null || BigDecimal::of($days)->isNegative()) {
            $check->error('available_days', 'invalid', (string) trans('imports.invalid_number', [], $context->locale));
        }
        if (! $check->ok()) {
            return $check;
        }

        $this->accrual->sync((string) $employmentId);
        $period = VacationAccrualPeriod::query()->where('employment_id', $employmentId)->where('period_start', $check->parsed['period_start'])->first();
        if ($period === null) {
            $check->error('period_start', 'not_found', (string) trans('vacations.import.period_missing', [], $context->locale));
        } elseif (! in_array($period->status, ['earned', 'overdue'], true)) {
            $check->error('period_start', 'not_earned', (string) trans('vacations.import.period_not_earned', [], $context->locale));
        } elseif (VacationLedgerEntry::query()->where('vacation_accrual_period_id', $period->id)->where('entry_type', LedgerEntryType::OPENING_BALANCE)
            ->whereNotIn('id', VacationLedgerEntry::query()->whereNotNull('reverses_entry_id')->select('reverses_entry_id'))->exists()
            || isset($context->memo['vacation_balance.'.$period->id])) {
            $check->error('period_start', 'duplicate', (string) trans('vacations.import.already_opened', [], $context->locale));
        } elseif (BigDecimal::of((string) $days)->isGreaterThan((string) $period->entitled_days)) {
            $check->error('available_days', 'above_entitlement', (string) trans('vacations.import.above_entitlement', ['days' => (string) BigDecimal::of((string) $period->entitled_days)->stripTrailingZeros()], $context->locale));
        } else {
            $check->parsed['period_id'] = $period->id;
            $context->memo['vacation_balance.'.$period->id] = true;
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $periodId = (string) $parsed['period_id'];
        $employmentId = (string) $parsed['employment_id'];
        $target = BigDecimal::of((string) $parsed['days']);
        $current = $this->accrual->availableInPeriod($periodId);
        $entry = VacationLedgerEntry::query()->create([
            'employment_id' => $employmentId, 'vacation_accrual_period_id' => $periodId, 'entry_type' => LedgerEntryType::OPENING_BALANCE,
            'days' => (string) $target->minus($current)->toScale(2), 'effective_on' => (string) $parsed['effective_on'],
            'reason' => (string) trans('vacations.import.reason', ['days' => (string) $target->stripTrailingZeros()]),
            'evidence' => 'import:'.$context->jobId, 'created_by_user_id' => $context->userId, 'idempotency_key' => 'opening:'.$periodId.':'.$context->jobId,
        ]);
        $this->audit->record('vacation_balance.imported', 'employment', $employmentId, null,
            ['period_id' => $periodId, 'available_days' => (string) $target->toScale(2), 'entry_id' => $entry->id], 'import:'.$context->jobId);

        return ['entity_id' => (string) $entry->id, 'previous' => ['__created' => true]];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $entry = VacationLedgerEntry::query()->find($entityId);
        if ($entry === null) {
            return null;
        }
        $used = VacationLedgerEntry::query()->where('vacation_accrual_period_id', $entry->vacation_accrual_period_id)
            ->whereIn('entry_type', [LedgerEntryType::SCHEDULED, LedgerEntryType::TAKEN, LedgerEntryType::SOLD, LedgerEntryType::SETTLEMENT])
            ->where('created_at', '>', $entry->created_at)->exists();

        return $used ? (string) trans('imports.changed_after_import', [], $context->locale) : null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $entry = VacationLedgerEntry::query()->find($entityId);
        if ($entry === null || VacationLedgerEntry::query()->where('reverses_entry_id', $entry->id)->exists()) {
            return;
        }
        // El libro es de solo inserción: deshacer es un contraasiento.
        VacationLedgerEntry::query()->create([
            'employment_id' => $entry->employment_id, 'vacation_accrual_period_id' => $entry->vacation_accrual_period_id,
            'entry_type' => LedgerEntryType::REVERSAL, 'days' => (string) BigDecimal::of((string) $entry->days)->negated()->toScale(2),
            'effective_on' => now()->toDateString(), 'reverses_entry_id' => $entry->id, 'reason' => (string) trans('vacations.import.reverted'),
            'created_by_user_id' => $context->userId,
        ]);
        $this->audit->record('vacation_balance.import_reverted', 'employment', (string) $entry->employment_id, ['entry_id' => $entry->id], null, 'import:'.$context->jobId);
    }
}
