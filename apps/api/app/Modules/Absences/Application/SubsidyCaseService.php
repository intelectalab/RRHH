<?php

namespace App\Modules\Absences\Application;

use App\Modules\Absences\Domain\SubsidyCaseStateMachine;
use App\Modules\Absences\Infrastructure\Models\AbsenceSubsidyCase;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AUS-3 · Seguimiento del trámite de reembolso de un subsidio (absence_subsidy_cases).
 *
 * El expediente lo abre MedicalLeaveSplit al aprobarse un descanso con días de subsidio. Quien gestiona
 * subsidios (time.absences.manage_subsidy) lo presenta ante la seguridad social, registra la observación,
 * la aprobación o el rechazo, y el reembolso recibido. Cada paso es una acción (regla 3) y queda auditado.
 * Los montos viajan como {amount, currency} con decimal exacto.
 */
final class SubsidyCaseService
{
    public function __construct(
        private readonly SubsidyCaseStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function act(AbsenceSubsidyCase $case, string $action, array $input): AbsenceSubsidyCase
    {
        if (! in_array($action, SubsidyCaseStateMachine::USER_ACTIONS, true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('absences.subsidies.invalid_action'), [], ['current_status' => $case->status, 'allowed_actions' => $this->machine->userActions($case->status)]);
        }
        $this->machine->getNextState($case->status, $action);

        return DB::transaction(function () use ($case, $action, $input): AbsenceSubsidyCase {
            /** @var AbsenceSubsidyCase $locked */
            $locked = AbsenceSubsidyCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $next = $this->machine->getNextState($locked->status, $action);
            $before = $this->auditable($locked);
            $changes = match ($action) {
                SubsidyCaseStateMachine::FILE => [
                    'filed_on' => $this->date($input, 'filed_on', true),
                    'filing_reference' => self::text($input['filing_reference'] ?? null) ?? $locked->filing_reference,
                    'amount_requested' => $this->amount($input, 'amount_requested', $locked) ?? $locked->amount_requested,
                    'observation' => null,
                ],
                SubsidyCaseStateMachine::OBSERVE => ['observation' => $this->reason($input, 'reason')],
                SubsidyCaseStateMachine::APPROVE => ['amount_approved' => $this->amount($input, 'amount_approved', $locked) ?? $locked->amount_requested],
                SubsidyCaseStateMachine::REJECT => ['decision_reason' => $this->reason($input, 'reason')],
                default => [
                    'reimbursed_on' => $this->date($input, 'reimbursed_on', true),
                    'amount_reimbursed' => $this->amount($input, 'amount_reimbursed', $locked, true),
                ],
            };
            if ($action === SubsidyCaseStateMachine::FILE && $changes['filed_on'] !== null && $changes['filed_on'] > now()->toDateString()) {
                throw ValidationException::withMessages(['filed_on' => [(string) trans('absences.subsidies.future_date')]]);
            }
            $locked->forceFill(['status' => $next] + $changes);
            $locked->save();
            $this->audit->record('absence_subsidy.'.$action, AbsenceSubsidyCase::SUBJECT, $locked->id, $before, $this->auditable($locked), self::text($input['reason'] ?? null));

            return $locked->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AbsenceSubsidyCase $c): array
    {
        $employment = DB::table('employments as e')->leftJoin('people as p', 'p.id', '=', 'e.person_id')
            ->where('e.id', $c->employment_id)->first(['e.employee_code', 'e.person_id', 'e.legal_entity_id', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname']);
        $absence = DB::table('absence_requests')->where('id', $c->absence_request_id)->first(['starts_on', 'ends_on', 'status']);
        $money = fn (?string $v): ?array => $v === null ? null : ['amount' => self::decimal($v), 'currency' => $c->currency];
        $due = $c->day('filing_due_on');

        return [
            'id' => $c->id,
            'absence_request_id' => $c->absence_request_id,
            'employment_id' => $c->employment_id,
            'person_id' => $employment?->person_id,
            'person_name' => $employment === null ? null : trim($employment->first_names.' '.$employment->paternal_surname.' '.($employment->maternal_surname ?? '')),
            'employee_code' => $employment?->employee_code,
            'legal_entity_id' => $employment?->legal_entity_id,
            'kind' => $c->kind,
            'status' => $c->status,
            'subsidized_days' => self::number((string) $c->subsidized_days),
            'starts_on' => $c->day('starts_on'),
            'ends_on' => $c->day('ends_on'),
            'absence_starts_on' => $absence === null ? null : substr((string) $absence->starts_on, 0, 10),
            'absence_ends_on' => $absence === null ? null : substr((string) $absence->ends_on, 0, 10),
            'absence_status' => $absence?->status,
            'year' => $c->year,
            'filing_due_on' => $due,
            'is_overdue' => $due !== null && $c->status === 'pending_filing' && $due < now()->toDateString(),
            'filed_on' => $c->day('filed_on'),
            'filing_reference' => $c->filing_reference,
            'amount_requested' => $money($c->amount_requested),
            'amount_approved' => $money($c->amount_approved),
            'amount_reimbursed' => $money($c->amount_reimbursed),
            'reimbursed_on' => $c->day('reimbursed_on'),
            'observation' => $c->observation,
            'decision_reason' => $c->decision_reason,
            'allowed_actions' => $this->machine->userActions($c->status),
            'version' => $c->lock_version,
            'created_at' => $c->created_at->toIso8601String(),
            'updated_at' => $c->updated_at->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ Internos

    /**
     * @param  array<string, mixed>  $input
     */
    private function date(array $input, string $field, bool $required): ?string
    {
        $v = $input[$field] ?? null;
        if (! is_string($v) || $v === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => [(string) trans('absences.subsidies.date_required')]]);
            }

            return null;
        }

        return $v;
    }

    /**
     * Monto {amount, currency}: decimal exacto, no negativo y en la moneda del expediente.
     *
     * @param  array<string, mixed>  $input
     */
    private function amount(array $input, string $field, AbsenceSubsidyCase $case, bool $required = false): ?string
    {
        $v = $input[$field] ?? null;
        if (! is_array($v) || ! isset($v['amount'])) {
            if ($required) {
                throw ValidationException::withMessages([$field => [(string) trans('absences.subsidies.amount_required')]]);
            }

            return null;
        }
        $currency = is_string($v['currency'] ?? null) ? strtoupper((string) $v['currency']) : $case->currency;
        if ($case->currency !== null && $currency !== $case->currency) {
            throw ValidationException::withMessages([$field.'.currency' => [(string) trans('absences.subsidies.currency_mismatch', ['currency' => $case->currency])]]);
        }
        if ($case->currency === null && is_string($currency)) {
            $case->forceFill(['currency' => $currency]);
        }
        try {
            $amount = BigDecimal::of((string) $v['amount']);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field.'.amount' => [(string) trans('absences.subsidies.amount_invalid')]]);
        }
        if ($amount->isNegative() || $amount->getScale() > 2) {
            throw ValidationException::withMessages([$field.'.amount' => [(string) trans('absences.subsidies.amount_invalid')]]);
        }

        return (string) $amount->toScale(2);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function reason(array $input, string $field): string
    {
        $text = self::text($input[$field] ?? null);
        if ($text === null) {
            throw ValidationException::withMessages([$field => [(string) trans('absences.subsidies.reason_required')]]);
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(AbsenceSubsidyCase $c): array
    {
        return [
            'status' => $c->status, 'filed_on' => $c->day('filed_on'), 'filing_reference' => $c->filing_reference,
            'amount_requested' => $c->amount_requested, 'amount_approved' => $c->amount_approved,
            'amount_reimbursed' => $c->amount_reimbursed, 'currency' => $c->currency, 'reimbursed_on' => $c->day('reimbursed_on'),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function decimal(string $s): string
    {
        return (string) BigDecimal::of($s)->toScale(2);
    }

    private static function number(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
