<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Domain\EmploymentStateMachine;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-11 · Registro del cese de la relación laboral (ME-01).
 *
 * 1) Registrar (Activa o Suspendida → En proceso de cese): fecha de cese, último día de labores, motivo del
 *    catálogo (Tabla 17), quién lo decide y detalle. Se valida RN-COL-09 (no antes del último periodo de
 *    planilla cerrado) y RN-COL-10 (pendientes: advierte o bloquea según parámetro). Emite
 *    offboarding.started para que offboarding y liquidación arranquen; aquí no se calcula nada.
 * 2) Hacerlo efectivo (En proceso de cese → Cesada) el día del cese, por el sistema: cierra con vigencia
 *    todo lo que la relación tenía abierto (asignación, compensación, régimen, afiliaciones, distribución
 *    de costos…) en la fecha de cese, cierra el contrato, libera la posición y emite employee.terminated.
 *    Un cese con fecha de hoy o pasada se hace efectivo al registrarlo; con fecha futura, ese día.
 * 3) Revertir (En proceso de cese → Activa) antes de la fecha, con motivo, si la liquidación no se pagó.
 *
 * Nada se borra (RN-COL-03): la relación queda «Cesada» con su fecha de fin y toda su historia.
 */
final class TerminationService
{
    /** Estados del contrato que se cierran en la fecha de cese y los que se cancelan (aún no rigen). */
    private const CONTRACT_RUNNING = ['active', 'signed'];

    private const CONTRACT_PENDING = ['draft', 'pending_signature', 'pending_approval'];

    /** @var list<string>|null */
    private static ?array $validityTables = null;

    public function __construct(
        private readonly EmploymentStateMachine $machine,
        private readonly TerminationReasonCatalog $reasons,
        private readonly LegalParameters $legal,
        private readonly ParameterResolver $parameters,
        private readonly PositionOccupancy $occupancy,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{employment: Employment, warnings: list<array{code: string, message: string}>}
     */
    public function register(Employment $employment, array $input, ?string $userId): array
    {
        $this->machine->getNextState($employment->status, 'terminate');
        $this->reasons->ensureCountryReasons();
        $reason = DB::table('termination_reasons')->where('id', $input['termination_reason_id'] ?? null)->whereNull('deleted_at')->first();
        if ($reason === null) {
            throw ValidationException::withMessages(['termination_reason_id' => [(string) trans('people.termination.reason_invalid')]]);
        }
        $date = EffectiveDated::day((string) $input['termination_date']);
        $lastDay = isset($input['last_working_day']) && is_string($input['last_working_day']) ? EffectiveDated::day($input['last_working_day']) : $date;
        $notice = isset($input['notice_date']) && is_string($input['notice_date']) ? EffectiveDated::day($input['notice_date']) : null;
        $hire = $employment->hire_date->toDateString();
        if ($date < $hire) {
            throw ValidationException::withMessages(['termination_date' => [(string) trans('people.termination.before_hire', ['date' => $hire])]]);
        }
        if ($lastDay > $date || $lastDay < $hire) {
            throw ValidationException::withMessages(['last_working_day' => [(string) trans('people.termination.last_day_invalid')]]);
        }
        if ($notice !== null && $notice > $date) {
            throw ValidationException::withMessages(['notice_date' => [(string) trans('people.termination.notice_after_date')]]);
        }
        $this->assertAfterClosedPeriod($employment, $date);
        $this->assertNoLaterChanges($employment, $date);

        $pending = $this->pendingItems($employment, $date);
        if ($pending !== [] && $this->parameters->value('colaboradores.cese_bloquea_pendientes', $employment->legal_entity_id) === true) {
            throw new AyniException('pending_items', 422, (string) trans('people.termination.pending_blocked'), [], ['pending_items' => $pending]);
        }
        $warnings = array_map(fn (array $p): array => ['code' => 'pending_'.$p['code'], 'message' => (string) trans('people.termination.pending_'.$p['code'], ['count' => $p['count']])], $pending);
        $category = (string) $reason->category;
        if ($category === 'resignation') {
            $warnings = array_merge($warnings, $this->noticeWarnings($employment, $notice, $date));
        }
        $initiatedBy = isset($input['initiated_by']) && is_string($input['initiated_by']) ? $input['initiated_by'] : TerminationReasonCatalog::initiator($category);
        $detail = isset($input['detail']) && is_string($input['detail']) && trim($input['detail']) !== '' ? trim($input['detail']) : null;
        $eligible = array_key_exists('is_rehire_eligible', $input) && $input['is_rehire_eligible'] !== null ? (bool) $input['is_rehire_eligible'] : null;

        $result = DB::transaction(function () use ($employment, $reason, $date, $lastDay, $notice, $initiatedBy, $detail, $eligible, $pending, $userId): Employment {
            /** @var Employment $locked */
            $locked = Employment::query()->whereKey($employment->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $to = $this->machine->getNextState($from, 'terminate');
            $terminationId = (string) DB::table('terminations')->insertGetId([
                'tenant_id' => $locked->tenant_id, 'employment_id' => $locked->id, 'termination_reason_id' => $reason->id,
                'notice_date' => $notice, 'last_working_day' => $lastDay, 'termination_date' => $date, 'initiated_by' => $initiatedBy,
                'detail' => $detail, 'is_rehire_eligible' => $eligible, 'status' => 'in_progress',
            ]);
            $locked->status = $to;
            $locked->termination_date = Carbon::parse($date);
            $locked->lock_version++;
            $locked->save();
            $this->statusEvent($locked, $from, $to, (string) $reason->code, now()->toDateString(), $userId, null);
            $this->outbox->record(DomainEventTypes::OFFBOARDING_STARTED, 'employment', $locked->id, [
                'employment_id' => $locked->id, 'termination_id' => $terminationId, 'person_id' => $locked->person_id,
                'legal_entity_id' => $locked->legal_entity_id, 'termination_date' => $date, 'last_working_day' => $lastDay,
                'reason' => (string) $reason->code, 'statutory_code' => $reason->statutory_code, 'initiated_by' => $initiatedBy,
                'pending_items' => $pending,
            ]);
            // El detalle es sensible: la auditoría solo deja constancia de que existe.
            $this->audit->record('employment.terminate', 'employment', $locked->id, ['status' => $from, 'termination_date' => null], [
                'status' => $to, 'termination_date' => $date, 'last_working_day' => $lastDay, 'termination_reason' => (string) $reason->code,
                'initiated_by' => $initiatedBy, 'is_rehire_eligible' => $eligible, 'has_detail' => $detail !== null,
            ]);
            if ($date <= now()->toDateString()) {
                // Cese de hoy o de una fecha pasada: rige ya.
                $locked = $this->effect($locked, $userId);
            }

            return $locked->refresh();
        });

        return ['employment' => $result, 'warnings' => $warnings];
    }

    /**
     * En proceso de cese → Cesada. Solo el sistema, el día del cese (también al registrar uno de hoy o pasado).
     */
    public function makeEffective(Employment $employment, ?string $userId = null): Employment
    {
        return DB::transaction(function () use ($employment, $userId): Employment {
            /** @var Employment $locked */
            $locked = Employment::query()->whereKey($employment->id)->lockForUpdate()->firstOrFail();

            return $this->effect($locked, $userId)->refresh();
        });
    }

    /**
     * En proceso de cese → Activa, antes de la fecha de cese y si la liquidación no se pagó.
     */
    public function revert(Employment $employment, ?string $reason, ?string $userId): Employment
    {
        $this->machine->getNextState($employment->status, 'cancel-termination');
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('people.reason_required')]]);
        }
        if ($employment->termination_date !== null && $employment->termination_date->toDateString() <= now()->toDateString()) {
            throw new AyniException('invalid_transition', 409, (string) trans('people.termination.already_effective'));
        }
        if (DB::table('settlements')->where('employment_id', $employment->id)->where('status', 'paid')->whereNull('deleted_at')->exists()) {
            throw new AyniException('invalid_transition', 409, (string) trans('people.termination.settlement_paid'));
        }

        return DB::transaction(function () use ($employment, $reason, $userId): Employment {
            /** @var Employment $locked */
            $locked = Employment::query()->whereKey($employment->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $to = $this->machine->getNextState($from, 'cancel-termination');
            $termination = $this->openTermination($locked->id);
            $previousDate = $locked->termination_date?->toDateString();
            if ($termination !== null) {
                DB::table('terminations')->where('id', $termination['id'])->update(['status' => 'cancelled', 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
            }
            $locked->status = $to;
            $locked->termination_date = null;
            $locked->lock_version++;
            $locked->save();
            $this->statusEvent($locked, $from, $to, 'termination_cancelled', now()->toDateString(), $userId, $reason);
            $this->outbox->record(DomainEventTypes::OFFBOARDING_CANCELLED, 'employment', $locked->id, [
                'employment_id' => $locked->id, 'termination_id' => $termination['id'] ?? null, 'person_id' => $locked->person_id,
                'legal_entity_id' => $locked->legal_entity_id, 'termination_date' => $previousDate,
            ]);
            $this->audit->record('employment.cancel-termination', 'employment', $locked->id,
                ['status' => $from, 'termination_date' => $previousDate], ['status' => $to, 'termination_date' => null], $reason);

            return $locked->refresh();
        });
    }

    /**
     * Hace efectivos los ceses cuya fecha llegó. Uno que falla no detiene a los demás.
     *
     * @return array{applied: int, blocked: array<int, string>}
     */
    public function applyDue(): array
    {
        $applied = 0;
        $blocked = [];
        $due = Employment::query()->where('status', 'terminating')->whereDate('termination_date', '<=', now()->toDateString())->orderBy('termination_date')->get();
        foreach ($due as $employment) {
            try {
                $this->makeEffective($employment);
                $applied++;
            } catch (AyniException $e) {
                $blocked[] = $employment->id.': '.$e->getMessage();
            }
        }

        return ['applied' => $applied, 'blocked' => $blocked];
    }

    /**
     * Cese vigente o ya ejecutado de la relación (no los revertidos), con su motivo.
     *
     * @return array<string, mixed>|null
     */
    public function current(Employment $employment, bool $withDetail): ?array
    {
        $row = DB::table('terminations as t')->join('termination_reasons as r', 'r.id', '=', 't.termination_reason_id')
            ->where('t.employment_id', $employment->id)->where('t.status', '<>', 'cancelled')->whereNull('t.deleted_at')
            ->orderByDesc('t.created_at')->first(['t.*', 'r.code as reason_code', 'r.name as reason_name', 'r.category as reason_category', 'r.statutory_code']);
        if ($row === null) {
            return null;
        }
        $data = [
            'id' => (string) $row->id, 'status' => (string) $row->status, 'termination_reason_id' => (string) $row->termination_reason_id,
            'reason_code' => (string) $row->reason_code, 'reason_name' => (string) $row->reason_name, 'category' => (string) $row->reason_category,
            'statutory_code' => $row->statutory_code === null ? null : (string) $row->statutory_code, 'initiated_by' => (string) $row->initiated_by,
            'notice_date' => $row->notice_date === null ? null : (string) $row->notice_date, 'last_working_day' => (string) $row->last_working_day,
            'termination_date' => (string) $row->termination_date, 'is_rehire_eligible' => $row->is_rehire_eligible === null ? null : (bool) $row->is_rehire_eligible,
        ];
        if ($withDetail) {
            $data['detail'] = $row->detail === null ? null : (string) $row->detail;
        }

        return $data;
    }

    private function effect(Employment $locked, ?string $userId): Employment
    {
        $from = $locked->status;
        $to = $this->machine->getNextState($from, 'make-effective');
        $date = $locked->termination_date?->toDateString();
        if ($date === null || $date > now()->toDateString()) {
            throw new AyniException('invalid_transition', 409, (string) trans('people.termination.not_due'));
        }
        $positions = DB::table('employment_assignments')->where('employment_id', $locked->id)->whereNotNull('position_id')
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))->pluck('position_id')->all();

        // Todo lo que la relación tenía abierto termina el día del cese (regla 2: se cierra, no se borra).
        foreach ($this->validityTables() as $table) {
            DB::table($table)->where('employment_id', $locked->id)->where('valid_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $date))->update(['valid_to' => $date]);
        }
        DB::table('contracts')->where('employment_id', $locked->id)->whereNull('deleted_at')->whereIn('status', self::CONTRACT_RUNNING)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $date))->update(['valid_to' => $date, 'status' => 'terminated', 'updated_at' => now()]);
        DB::table('contracts')->where('employment_id', $locked->id)->whereNull('deleted_at')->whereIn('status', self::CONTRACT_PENDING)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        $termination = $this->openTermination($locked->id);
        if ($termination !== null) {
            DB::table('terminations')->where('id', $termination['id'])->update(['status' => 'executed', 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
        }
        $reason = $termination === null ? null : DB::table('termination_reasons')->where('id', $termination['termination_reason_id'])->first(['code', 'statutory_code']);

        $locked->status = $to;
        $locked->lock_version++;
        $locked->save();
        $this->statusEvent($locked, $from, $to, 'termination_effective', $date, $userId, null);
        foreach (array_unique($positions) as $positionId) {
            // RN-POS-04 · Al cesar su ocupante la posición vuelve a vacante (cuenta desde el día siguiente al cese).
            $this->occupancy->sync((string) $positionId, $userId);
        }
        $this->outbox->record(DomainEventTypes::EMPLOYEE_TERMINATED, 'employment', $locked->id, [
            'employee_id' => $locked->id, 'employment_id' => $locked->id, 'person_id' => $locked->person_id,
            'legal_entity_id' => $locked->legal_entity_id, 'termination_id' => $termination['id'] ?? null, 'termination_date' => $date,
            'last_working_day' => $termination['last_working_day'] ?? null, 'reason' => $reason?->code, 'statutory_code' => $reason?->statutory_code,
            'position_ids' => array_values(array_unique(array_map('strval', $positions))),
        ]);
        $this->audit->record('employment.make-effective', 'employment', $locked->id, ['status' => $from], ['status' => $to, 'termination_date' => $date]);

        return $locked;
    }

    /**
     * Cese en curso de la relación (registrado y aún no ejecutado ni revertido).
     *
     * @return array{id: string, termination_reason_id: string, last_working_day: string}|null
     */
    private function openTermination(string $employmentId): ?array
    {
        $row = DB::table('terminations')->where('employment_id', $employmentId)->whereIn('status', ['in_progress', 'approved', 'pending_approval', 'draft'])
            ->whereNull('deleted_at')->orderByDesc('created_at')->first(['id', 'termination_reason_id', 'last_working_day']);

        return $row === null ? null : [
            'id' => (string) $row->id, 'termination_reason_id' => (string) $row->termination_reason_id, 'last_working_day' => (string) $row->last_working_day,
        ];
    }

    /** RN-COL-09 · El cese no puede caer en un periodo de planilla ya cerrado (el ajuste retroactivo es de M-PER). */
    private function assertAfterClosedPeriod(Employment $employment, string $date): void
    {
        $groups = DB::table('payroll_group_members')->where('employment_id', $employment->id)->pluck('payroll_group_id')->all();
        $closedUntil = DB::table('payroll_periods as p')->join('payroll_groups as g', 'g.id', '=', 'p.payroll_group_id')
            ->where('p.kind', 'regular')->whereIn('p.status', ['closed', 'paid', 'accounted'])
            ->when($groups !== [], fn ($q) => $q->whereIn('p.payroll_group_id', $groups), fn ($q) => $q->where('g.legal_entity_id', $employment->legal_entity_id))
            ->max('p.ends_on');
        if ($closedUntil !== null && $date <= (string) $closedUntil) {
            throw new AyniException('closed_period', 422, (string) trans('people.termination.closed_period', ['date' => (string) $closedUntil]));
        }
    }

    /** Un cambio con vigencia programado para después del cese debe anularse antes (no se borra solo). */
    private function assertNoLaterChanges(Employment $employment, string $date): void
    {
        $later = [];
        foreach ($this->validityTables() as $table) {
            if (DB::table($table)->where('employment_id', $employment->id)->where('valid_from', '>', $date)->exists()) {
                $later[] = $table;
            }
        }
        if (DB::table('contracts')->where('employment_id', $employment->id)->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'superseded'])->where('valid_from', '>', $date)->exists()) {
            $later[] = 'contracts';
        }
        if ($later !== []) {
            throw new AyniException('future_changes', 409, (string) trans('people.termination.future_changes'), [], ['tables' => $later]);
        }
    }

    /**
     * RN-COL-10 · Pendientes a la fecha de cese: activos sin devolver, préstamos y adelantos con saldo,
     * vacaciones aprobadas o por aprobar posteriores al cese.
     *
     * @return list<array{code: string, count: int}>
     */
    private function pendingItems(Employment $employment, string $date): array
    {
        $counts = [
            'assets' => DB::table('asset_assignments')->where('employment_id', $employment->id)->where('status', 'active')->whereNull('returned_on')->count(),
            'loans' => DB::table('loans')->where('employment_id', $employment->id)->whereNull('deleted_at')->whereIn('status', ['approved', 'active', 'disbursed'])
                ->where(fn ($q) => $q->whereNull('balance')->orWhere('balance', '>', 0))->count(),
            'advances' => DB::table('salary_advances')->where('employment_id', $employment->id)->whereNull('deleted_at')->whereIn('status', ['approved', 'paid'])->count(),
            'vacations' => DB::table('vacation_requests')->where('employment_id', $employment->id)->whereNull('deleted_at')
                ->whereIn('status', ['pending', 'approved', 'hr_review'])->where('starts_on', '>', $date)->count(),
        ];
        $out = [];
        foreach ($counts as $code => $count) {
            if ($count > 0) {
                $out[] = ['code' => $code, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * Renuncia: compara el preaviso registrado con el parámetro legal (por validar mientras no se confirme).
     *
     * @return list<array{code: string, message: string}>
     */
    private function noticeWarnings(Employment $employment, ?string $notice, string $date): array
    {
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
        $regime = DB::table('employment_labor_regimes')->where('employment_id', $employment->id)->where('valid_from', '<=', $date)
            ->orderByDesc('valid_from')->value('labor_regime_code');
        $rule = $this->legal->at($country, 'renuncia_preaviso_dias', $notice ?? $date, is_string($regime) ? $regime : null);
        if ($rule === null || $rule['pending_validation'] || ! is_numeric($rule['value'])) {
            return [['code' => 'notice_rule_pending', 'message' => (string) trans('people.termination.notice_rule_pending')]];
        }
        $required = (int) $rule['value'];
        $given = $notice === null ? 0 : (int) Carbon::parse($notice)->diffInDays(Carbon::parse($date));

        return $given < $required
            ? [['code' => 'short_notice', 'message' => (string) trans('people.termination.short_notice', ['given' => $given, 'required' => $required])]]
            : [];
    }

    private function statusEvent(Employment $employment, ?string $from, string $to, ?string $reasonCode, string $effectiveOn, ?string $userId, ?string $notes): void
    {
        DB::table('employment_status_events')->insert([
            'tenant_id' => $employment->tenant_id, 'employment_id' => $employment->id, 'from_status' => $from, 'to_status' => $to,
            'reason_code' => $reasonCode, 'effective_on' => $effectiveOn, 'changed_by_user_id' => $userId, 'notes' => $notes,
        ]);
    }

    /**
     * Tablas con vigencia de la relación (employment_id + valid_from + valid_to), salvo el contrato, que se
     * cierra con su estado. Se leen del catálogo para que una tabla nueva quede cubierta sin tocar el cese.
     *
     * @return list<string>
     */
    private function validityTables(): array
    {
        if (self::$validityTables === null) {
            /** @var list<string> $tables */
            $tables = DB::table('information_schema.columns as c')
                ->join('information_schema.tables as t', fn ($j) => $j->on('t.table_name', '=', 'c.table_name')->on('t.table_schema', '=', 'c.table_schema'))
                ->where('c.table_schema', 'public')->where('t.table_type', 'BASE TABLE')
                ->whereIn('c.column_name', ['employment_id', 'valid_from', 'valid_to'])->where('c.table_name', '<>', 'contracts')
                ->groupBy('c.table_name')->havingRaw('count(DISTINCT c.column_name) = 3')->orderBy('c.table_name')
                ->pluck('c.table_name')->map(fn ($t) => (string) $t)->values()->all();
            self::$validityTables = $tables;
        }

        return self::$validityTables;
    }
}
