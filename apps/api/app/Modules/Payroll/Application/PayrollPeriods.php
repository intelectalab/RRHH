<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\PayrollPeriodStateMachine;
use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollIncident;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Workflow\ApprovalRequests;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PER-1 · Grupos y periodos de planilla (ME-19).
 *
 * - Un periodo pertenece a un grupo (empresa, frecuencia, moneda); su población son los miembros del grupo con
 *   vigencia en el periodo o, si el grupo no tiene miembros explícitos, todas las relaciones de la empresa que
 *   no estén asignadas a otro grupo.
 * - No se abre un periodo si el anterior del mismo grupo y tipo no está cerrado, salvo con payroll.periods.reopen
 *   (RN-PER-11 por analogía con el cierre).
 * - Los estados cambian solo por acciones: cerrar y reabrir la recepción de incidencias, enviar, aprobar (por
 *   alguien distinto de quien calculó o cargó incidencias, RN-PER-01), devolver, cerrar (con el anterior cerrado,
 *   RN-PER-11), reabrir (sin declaración presentada ni pago, RN-PER-08 y ADR 0017) y volver a calcular.
 *   Calcular (PER-4) y registrar el pago (PER-10) se agregan en sus historias.
 */
final class PayrollPeriods
{
    /** Acciones que ya tienen ruta; el resto de la máquina llega con PER-4 y PER-10. */
    public const ROUTED_ACTIONS = ['close-incidents', 'reopen-incidents', 'calculate', 'submit', 'approve', 'reject', 'close', 'reopen', 'reopen-calculation', 'mark-paid'];

    /** PER-8 · Tipo de trámite del flujo de aprobación de un periodo (WFL). */
    public const SUBJECT_TYPE = 'payroll_period';

    /** PER-7 · Estados en que se puede simular (la simulación no cambia el periodo). */
    public const SIMULATABLE = ['open', 'incidents_closed', 'calculated', 'in_review'];

    /** Estados en que se reciben y corrigen incidencias (se bloquean desde el envío a aprobación). */
    public const EDITABLE_STATUSES = ['open', 'calculated'];

    private const CLOSED = ['closed', 'paid', 'accounted'];

    public function __construct(
        private readonly PayrollPeriodStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly PayrollRuns $runs,
        private readonly PayrollReview $review,
        private readonly ApprovalRequests $approvals,
    ) {}

    /**
     * PER-4 · Calcular (o recalcular) el periodo: abre una corrida en segundo plano (NOM-8) sobre su población,
     * sus incidencias y cortes de tiempo (PER-2/3), los conceptos vigentes (CPT) y los parámetros (PAR). Recalcular
     * reemplaza el resultado anterior mientras el periodo no esté aprobado (las corridas previas quedan
     * descartadas, no se borran). Con alcance parcial (algunas relaciones o una unidad) solo se recalculan esas:
     * el resultado vigente de las demás pasa a la corrida nueva sin cambios.
     *
     * @param  array{employment_ids?: list<string>|null, org_unit_id?: string|null}  $scope
     * @return array{period: PayrollPeriod, run_id: string}
     */
    public function calculate(PayrollPeriod $p, ?string $userId, array $scope = []): array
    {
        $next = $this->machine->getNextState($p->status, 'calculate');
        if (DB::table('payroll_runs')->where('payroll_period_id', $p->id)->whereIn('status', ['queued', 'running'])->whereIn('kind', ['calculation', 'recalculation'])->exists()) {
            throw new AyniException('run_in_progress', 409, (string) trans('payroll.periods.run_in_progress'));
        }
        $partial = ! empty($scope['employment_ids']) || ! empty($scope['org_unit_id']);
        $previous = DB::table('payroll_runs')->where('payroll_period_id', $p->id)->whereIn('kind', ['calculation', 'recalculation'])
            ->whereIn('status', ['completed', 'failed'])->orderByDesc('run_number')->first(['id']);
        $selected = $partial ? $this->runs->resolve($p->id, $scope) : [];
        $runId = DB::transaction(function () use ($p, $next, $previous, $partial, $selected, $userId): string {
            $tenant = TenancyScope::requireTenant();
            $runId = $this->runs->open($p->id, [], $previous === null ? 'calculation' : 'recalculation', $userId, false);
            if ($previous !== null) {
                if ($partial) {
                    $this->carryOver((string) $previous->id, $runId, $selected, $tenant);
                }
                DB::table('payroll_runs')->where('id', $previous->id)->update(['status' => 'discarded', 'updated_at' => now()]);
            }
            $before = $p->status;
            $p->fill(['status' => $next])->save();
            $this->audit->record('payroll_period.calculate', 'payroll_period', $p->id, ['status' => $before], ['status' => $p->status, 'payroll_run_id' => $runId]);
            $this->outbox->record(DomainEventTypes::PAYROLL_RUN_REQUESTED, 'payroll_run', $runId, ['payroll_run_id' => $runId, 'requested_by' => $userId]);

            return $runId;
        });

        return ['period' => $p, 'run_id' => $runId];
    }

    /**
     * Copia a la corrida nueva el resultado vigente de las relaciones que no se recalculan (filas y líneas con su
     * traza); el procesamiento de la corrida solo calcula las que faltan.
     *
     * @param  list<string>  $recalculate
     */
    private function carryOver(string $fromRun, string $toRun, array $recalculate, string $tenant): void
    {
        $rows = DB::table('payroll_run_employments')->where('payroll_run_id', $fromRun)->whereNotIn('employment_id', $recalculate)->get();
        foreach ($rows as $row) {
            $data = (array) $row;
            $oldId = (string) $data['id'];
            unset($data['id'], $data['created_at'], $data['updated_at']);
            $newId = (string) DB::table('payroll_run_employments')->insertGetId(['payroll_run_id' => $toRun] + $data);
            foreach (DB::table('payroll_run_lines')->where('payroll_run_employment_id', $oldId)->get() as $line) {
                $l = (array) $line;
                unset($l['id'], $l['created_at'], $l['updated_at']);
                DB::table('payroll_run_lines')->insert(['payroll_run_id' => $toRun, 'payroll_run_employment_id' => $newId] + $l);
            }
            DB::table('payroll_runs')->where('id', $toRun)->update([$row->status === 'error' ? 'employments_failed' : 'employments_done' => DB::raw(($row->status === 'error' ? 'employments_failed' : 'employments_done').' + 1')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createGroup(array $data): PayrollGroup
    {
        if (PayrollGroup::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('conflict', 409, (string) trans('payroll.periods.group_exists', ['code' => (string) $data['code']]));
        }
        $currency = $data['currency'] ?? DB::table('legal_entities')->where('id', $data['legal_entity_id'])->value('currency');
        $g = PayrollGroup::query()->create([
            'code' => $data['code'], 'name' => $data['name'], 'legal_entity_id' => $data['legal_entity_id'],
            'pay_frequency' => $data['pay_frequency'] ?? 'monthly', 'currency' => (string) ($currency ?? 'PEN'),
            'labor_regime_code' => $data['labor_regime_code'] ?? null, 'payment_day' => $data['payment_day'] ?? null, 'cutoff_day' => $data['cutoff_day'] ?? null,
        ]);
        $this->audit->record('payroll_group.created', 'payroll_group', $g->id, null, $g->only(['code', 'name', 'legal_entity_id', 'pay_frequency', 'currency']));

        return $g;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(PayrollGroup $g, array $data): PayrollGroup
    {
        $before = $g->only(array_keys($data));
        $g->fill($data)->save();
        $this->audit->record('payroll_group.updated', 'payroll_group', $g->id, $before, $g->only(array_keys($data)));

        return $g;
    }

    /** Asigna una relación al grupo desde una fecha: cierra su pertenencia vigente y abre otra (regla 2). */
    public function assignMember(PayrollGroup $g, string $employmentId, string $validFrom): string
    {
        return DB::transaction(function () use ($g, $employmentId, $validFrom): string {
            $current = DB::table('payroll_group_members')->where('employment_id', $employmentId)
                ->where('valid_from', '<=', $validFrom)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $validFrom))->first();
            if ($current !== null) {
                if ((string) $current->payroll_group_id === $g->id) {
                    throw new AyniException('conflict', 409, (string) trans('payroll.periods.already_member'));
                }
                if ((string) $current->valid_from === $validFrom) {
                    throw new AyniException('conflict', 409, (string) trans('payroll.periods.member_same_day'));
                }
                DB::table('payroll_group_members')->where('id', $current->id)
                    ->update(['valid_to' => CarbonImmutable::parse($validFrom)->subDay()->toDateString(), 'updated_at' => now()]);
            }
            if (DB::table('payroll_group_members')->where('employment_id', $employmentId)->where('valid_from', '>', $validFrom)->exists()) {
                throw new AyniException('conflict', 409, (string) trans('payroll.periods.member_future'));
            }
            $id = (string) DB::table('payroll_group_members')->insertGetId([
                'tenant_id' => $g->tenant_id, 'employment_id' => $employmentId, 'payroll_group_id' => $g->id, 'valid_from' => $validFrom,
            ]);
            $this->audit->record('payroll_group.member_assigned', 'payroll_group', $g->id,
                $current === null ? null : ['payroll_group_id' => (string) $current->payroll_group_id],
                ['employment_id' => $employmentId, 'valid_from' => $validFrom]);

            return $id;
        });
    }

    /**
     * Abre un periodo del grupo. Las fechas de un periodo regular mensual salen del año y número; el pago y el
     * corte de tiempo, de los días del grupo; el corte empieza el día siguiente al corte del periodo anterior.
     *
     * @param  array<string, mixed>  $data
     */
    public function open(PayrollGroup $g, array $data, ?string $userId, bool $canSkipPrevious): PayrollPeriod
    {
        $kind = (string) ($data['kind'] ?? 'regular');
        $year = (int) $data['period_year'];
        $number = (int) $data['period_number'];
        $starts = isset($data['starts_on']) ? (string) $data['starts_on'] : null;
        $ends = isset($data['ends_on']) ? (string) $data['ends_on'] : null;
        if ($starts === null || $ends === null) {
            if ($g->pay_frequency !== 'monthly' || $number < 1 || $number > 12) {
                throw new AyniException('validation_failed', 422, (string) trans('payroll.periods.dates_required'),
                    [['field' => 'starts_on', 'rule' => 'required', 'message' => (string) trans('payroll.periods.dates_required')]]);
            }
            $month = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $number));
            $starts ??= $month->toDateString();
            $ends ??= $month->endOfMonth()->toDateString();
        }
        if ($ends < $starts) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.periods.dates_order'),
                [['field' => 'ends_on', 'rule' => 'after_or_equal', 'message' => (string) trans('payroll.periods.dates_order')]]);
        }
        if (PayrollPeriod::query()->where('payroll_group_id', $g->id)->where('kind', $kind)->where('period_year', $year)->where('period_number', $number)->exists()) {
            throw new AyniException('conflict', 409, (string) trans('payroll.periods.exists'));
        }
        $previous = $this->previous($g->id, $kind, $starts);
        if ($previous !== null && ! in_array($previous->status, self::CLOSED, true) && ! $canSkipPrevious) {
            throw new AyniException('previous_period_open', 409, (string) trans('payroll.periods.previous_open', ['code' => $this->label($previous)]));
        }
        $end = CarbonImmutable::parse($ends);
        $payment = $data['payment_date'] ?? ($g->payment_day !== null ? $end->setDay(min($g->payment_day, $end->daysInMonth))->toDateString() : $ends);
        $cutoffTo = $data['attendance_to'] ?? ($g->cutoff_day !== null && $kind === 'regular'
            ? min($ends, $end->setDay(min($g->cutoff_day, $end->daysInMonth))->toDateString()) : $ends);
        $cutoffFrom = $data['attendance_from'] ?? ($previous !== null ? CarbonImmutable::parse($previous->cutoffTo())->addDay()->toDateString() : $starts);
        if ($cutoffTo < $cutoffFrom) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.periods.cutoff_order'),
                [['field' => 'attendance_to', 'rule' => 'after_or_equal', 'message' => (string) trans('payroll.periods.cutoff_order')]]);
        }

        return DB::transaction(function () use ($g, $kind, $year, $number, $starts, $ends, $payment, $cutoffFrom, $cutoffTo, $userId, $previous): PayrollPeriod {
            $p = PayrollPeriod::query()->create([
                'payroll_group_id' => $g->id, 'kind' => $kind, 'period_year' => $year, 'period_number' => $number,
                'starts_on' => $starts, 'ends_on' => $ends, 'payment_date' => $payment, 'attendance_from' => $cutoffFrom, 'attendance_to' => $cutoffTo,
                'statutory_period_code' => $kind === 'regular' ? substr(str_replace('-', '', $ends), 0, 6) : null, 'opened_by_user_id' => $userId,
            ]);
            $this->audit->record('payroll_period.opened', 'payroll_period', $p->id, null, [
                'payroll_group_id' => $g->id, 'kind' => $kind, 'period' => $year.'-'.$number, 'starts_on' => $starts, 'ends_on' => $ends,
                'previous_status' => $previous?->status,
            ]);
            $this->outbox->record(DomainEventTypes::PAYROLL_PERIOD_OPENED, 'payroll_period', $p->id, ['period_id' => $p->id, 'payroll_group_id' => $g->id]);

            return $p;
        });
    }

    /**
     * Ejecuta una acción de la máquina de estados con sus reglas.
     *
     * @param  array<string, mixed>  $input
     */
    public function transition(PayrollPeriod $p, string $action, ?string $userId, array $input = []): PayrollPeriod
    {
        $next = $this->machine->getNextState($p->status, $action);
        $reason = isset($input['reason']) && is_string($input['reason']) && trim($input['reason']) !== '' ? trim($input['reason']) : null;
        if (in_array($action, ['reject', 'reopen', 'reopen-calculation'], true) && $reason === null) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.periods.reason_required'),
                [['field' => 'reason', 'rule' => 'required', 'message' => (string) trans('payroll.periods.reason_required')]]);
        }
        $before = $p->status;
        $changes = ['status' => $next];
        $event = null;
        $fromFlow = ($input['_from_flow'] ?? false) === true;
        $run = $this->lastRun($p);
        $startFlow = false;
        switch ($action) {
            case 'submit':
                $this->assertNoErrors($p);
                if (DB::table('payroll_runs')->where('payroll_period_id', $p->id)->whereIn('status', ['queued', 'running'])->whereIn('kind', ['calculation', 'recalculation'])->exists()) {
                    throw new AyniException('run_in_progress', 409, (string) trans('payroll.periods.run_in_progress'));
                }
                // RN-PER-06 · Con anomalías sin revisar, el envío advierte y exige confirmación expresa.
                $open = $this->review->openCounts($p->id);
                if ($open['open'] > 0 && ($input['confirm_anomalies'] ?? false) !== true) {
                    throw new AyniException('anomalies_pending', 409, (string) trans('payroll.periods.anomalies_pending', ['count' => $open['open']]));
                }
                $changes += ['submitted_by_user_id' => $userId, 'submitted_at' => now(), 'return_reason' => null];
                $event = [DomainEventTypes::PAYROLL_SUBMITTED, ['period_id' => $p->id]];
                $startFlow = $this->hasFlow();
                break;
            case 'approve':
                // PER-8 · Con flujo de aprobación configurado, se aprueba desde Aprobaciones.
                if (! $fromFlow && $this->runningApproval($p->id) !== null) {
                    throw new AyniException('managed_by_flow', 409, (string) trans('payroll.periods.managed_by_flow'));
                }
                $this->assertSegregation($p, $userId);
                $changes += ['approved_by_user_id' => $userId, 'approved_at' => now()];
                $event = [DomainEventTypes::PAYROLL_APPROVED, ['payroll_run_id' => $run, 'approved_by' => $userId, 'period_id' => $p->id]];
                break;
            case 'reject':
                if (! $fromFlow && $this->runningApproval($p->id) !== null) {
                    throw new AyniException('managed_by_flow', 409, (string) trans('payroll.periods.managed_by_flow'));
                }
                $changes += ['return_reason' => $reason, 'submitted_at' => null, 'submitted_by_user_id' => null];
                $event = [DomainEventTypes::PAYROLL_RETURNED, ['period_id' => $p->id, 'reason' => $reason]];
                break;
            case 'reopen-calculation':
                $changes += ['approved_by_user_id' => null, 'approved_at' => null, 'submitted_at' => null, 'submitted_by_user_id' => null, 'return_reason' => $reason];
                $event = [DomainEventTypes::PAYROLL_RETURNED, ['period_id' => $p->id, 'reason' => $reason]];
                break;
            case 'close':
                $previous = $this->previous($p->payroll_group_id, $p->kind, (string) $p->day('starts_on'));
                if ($previous !== null && ! in_array($previous->status, self::CLOSED, true)) {
                    throw new AyniException('previous_period_open', 409, (string) trans('payroll.periods.previous_not_closed', ['code' => $this->label($previous)]));
                }
                // PER-8 · No se cierra con anomalías críticas sin revisar ni con parámetros «por validar» sin confirmar (RN-PAR-03).
                if ($this->review->openCounts($p->id)['critical'] > 0) {
                    throw new AyniException('critical_anomalies', 409, (string) trans('payroll.periods.critical_anomalies'));
                }
                $pending = $this->unconfirmedParameters($run);
                if ($pending !== []) {
                    throw new AyniException('parameters_unconfirmed', 409, (string) trans('payroll.periods.parameters_unconfirmed', ['codes' => implode(', ', $pending)]));
                }
                $changes += ['closed_at' => now(), 'closed_by_user_id' => $userId];
                $event = [DomainEventTypes::PAYROLL_CLOSED, ['payroll_run_id' => $run, 'closed_at' => now()->toIso8601String(), 'period_id' => $p->id]];
                break;
            case 'reopen':
                $this->assertNotDeclared($p);
                if (DB::table('payroll_payment_batches')->where('payroll_period_id', $p->id)->where('status', '<>', 'cancelled')->exists()) {
                    throw new AyniException('payment_batches_exist', 409, (string) trans('payroll.periods.payment_batches_exist'));
                }
                $changes += ['closed_at' => null, 'closed_by_user_id' => null, 'approved_at' => null, 'approved_by_user_id' => null,
                    'submitted_at' => null, 'submitted_by_user_id' => null, 'reopen_reason' => $reason];
                $event = [DomainEventTypes::PAYROLL_REOPENED, ['period_id' => $p->id, 'reason' => $reason]];
                break;
            case 'mark-paid':
                // RN-PER-12 · Se registra el pago con sus lotes confirmados (conciliados).
                $batches = DB::table('payroll_payment_batches')->where('payroll_period_id', $p->id)->where('status', '<>', 'cancelled')->get(['id', 'status', 'channel']);
                if ($batches->isEmpty() || $batches->contains(fn ($b) => $b->status !== 'confirmed')) {
                    throw new AyniException('payment_not_confirmed', 409, (string) trans('payroll.payments.not_confirmed'));
                }
                $paidAt = now();
                $changes += ['paid_at' => $paidAt, 'paid_by_user_id' => $userId, 'payment_method' => $batches->pluck('channel')->unique()->sort()->implode(',')];
                $event = [DomainEventTypes::PAYROLL_PAID, ['period_id' => $p->id, 'paid_at' => $paidAt->toIso8601String(), 'batches' => $batches->pluck('id')->map(fn ($v) => (string) $v)->all()]];
                break;
        }

        return DB::transaction(function () use ($p, $action, $changes, $before, $reason, $event, $run, $startFlow, $userId): PayrollPeriod {
            $p->fill($changes)->save();
            if ($action === 'close') {
                // RN-PER-03: cerrado, lo aprobado queda aplicado y ya no se modifica; la corrida queda congelada.
                PayrollIncident::query()->where('payroll_period_id', $p->id)->where('status', 'approved')->update(['status' => 'applied', 'updated_at' => now()]);
                if ($run !== null) {
                    DB::table('payroll_runs')->where('id', $run)->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
                }
            }
            if ($action === 'approve' && $run !== null) {
                DB::table('payroll_runs')->where('id', $run)->where('status', 'completed')->update(['status' => 'approved', 'approved_by_user_id' => $userId, 'updated_at' => now()]);
            }
            if (in_array($action, ['reject', 'reopen-calculation'], true) && $run !== null) {
                DB::table('payroll_runs')->where('id', $run)->where('status', 'approved')->update(['status' => 'completed', 'approved_by_user_id' => null, 'updated_at' => now()]);
            }
            if ($action === 'reopen') {
                PayrollIncident::query()->where('payroll_period_id', $p->id)->where('status', 'applied')->update(['status' => 'approved', 'updated_at' => now()]);
            }
            if ($startFlow && $userId !== null) {
                $legalEntity = PayrollGroup::query()->whereKey($p->payroll_group_id)->value('legal_entity_id');
                try {
                    $this->approvals->start(self::SUBJECT_TYPE, $p->id, $userId, ['legal_entity_id' => $legalEntity, 'kind' => $p->kind]);
                } catch (BusinessRuleViolatedException) {
                    // Ningún flujo aplica a este periodo (condiciones): se aprueba directamente.
                }
            }
            $this->audit->record('payroll_period.'.str_replace('-', '_', $action), 'payroll_period', $p->id, ['status' => $before], ['status' => $p->status], $reason);
            if ($event !== null) {
                $this->outbox->record($event[0], 'payroll_period', $p->id, $event[1]);
            }

            return $p;
        });
    }

    /**
     * PER-7 · Simular el periodo: abre una corrida de simulación en segundo plano con la población, incidencias y
     * cortes vigentes. No cambia el estado del periodo ni sus incidencias, ni escribe el libro de quinta; al terminar
     * deja la comparación con el periodo anterior y sus anomalías.
     *
     * @param  array{employment_ids?: list<string>|null, org_unit_id?: string|null}  $scope
     */
    public function simulate(PayrollPeriod $p, ?string $userId, array $scope = []): string
    {
        if (! in_array($p->status, self::SIMULATABLE, true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.periods.not_simulatable'));
        }

        return $this->runs->open($p->id, $scope, 'simulation', $userId);
    }

    /**
     * PER-9 · ADR 0017 · Planilla complementaria de un periodo cerrado (también declarado o pagado): un periodo
     * «special» del mismo grupo y mes tributario para pagos fuera del periodo regular. El resultado original no se
     * toca: lo que se pague va en la complementaria.
     *
     * @param  array{payment_date?: string|null, starts_on?: string|null, ends_on?: string|null}  $data
     */
    public function complementary(PayrollPeriod $original, ?string $userId, array $data): PayrollPeriod
    {
        if (! in_array($original->status, self::CLOSED, true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.periods.complementary_needs_closed'));
        }
        $starts = (string) ($data['starts_on'] ?? $original->day('starts_on'));
        $ends = (string) ($data['ends_on'] ?? $original->day('ends_on'));
        if ($ends < $starts) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.periods.dates_order'),
                [['field' => 'ends_on', 'rule' => 'after_or_equal', 'message' => (string) trans('payroll.periods.dates_order')]]);
        }

        return DB::transaction(function () use ($original, $userId, $data, $starts, $ends): PayrollPeriod {
            DB::table('payroll_periods')->where('id', $original->id)->lockForUpdate()->first(['id']);
            $number = (int) PayrollPeriod::query()->where('payroll_group_id', $original->payroll_group_id)->where('kind', 'special')
                ->where('period_year', $original->period_year)->max('period_number') + 1;
            $p = PayrollPeriod::query()->create([
                'payroll_group_id' => $original->payroll_group_id, 'kind' => 'special', 'period_year' => $original->period_year, 'period_number' => $number,
                'starts_on' => $starts, 'ends_on' => $ends, 'payment_date' => $data['payment_date'] ?? $original->day('payment_date'),
                'attendance_from' => $starts, 'attendance_to' => $ends, 'statutory_period_code' => $original->statutory_period_code,
                'opened_by_user_id' => $userId, 'complements_period_id' => $original->id,
            ]);
            $this->audit->record('payroll_period.complementary_opened', 'payroll_period', $p->id, null, [
                'complements_period_id' => $original->id, 'period' => $p->period_year.'-'.$p->period_number, 'starts_on' => $starts, 'ends_on' => $ends,
            ]);
            $this->outbox->record(DomainEventTypes::PAYROLL_PERIOD_OPENED, 'payroll_period', $p->id, ['period_id' => $p->id, 'payroll_group_id' => $p->payroll_group_id]);

            return $p;
        });
    }

    /** PER-8 · El flujo de aprobación aprobó el periodo: lo aprueba quien decidió (con la segregación de funciones). */
    public function approvedByFlow(string $periodId, ?string $deciderUserId): void
    {
        $p = PayrollPeriod::query()->find($periodId);
        if ($p instanceof PayrollPeriod && $p->status === 'in_review') {
            $this->transition($p, 'approve', $deciderUserId, ['_from_flow' => true]);
        }
    }

    /** PER-8 · El flujo devolvió o se canceló: el periodo vuelve a «calculado» con el motivo. */
    public function rejectedByFlow(string $periodId, ?string $reason): void
    {
        $p = PayrollPeriod::query()->find($periodId);
        if ($p instanceof PayrollPeriod && $p->status === 'in_review') {
            $this->transition($p, 'reject', null, ['_from_flow' => true, 'reason' => $reason ?? (string) trans('payroll.periods.flow_rejected')]);
        }
    }

    /** Aprobación en curso del periodo (WFL), si la hay. */
    public function runningApproval(string $periodId): ?string
    {
        $id = DB::table('workflow_instances')->where('subject_type', self::SUBJECT_TYPE)->where('subject_id', $periodId)
            ->whereIn('status', ['pending', 'running', 'escalated'])->orderByDesc('started_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    private function hasFlow(): bool
    {
        return DB::table('workflow_definitions')->where('kind', 'approval')->where('subject_type', self::SUBJECT_TYPE)
            ->where('status', 'active')->whereNotNull('current_version_id')->whereNull('deleted_at')->exists();
    }

    /**
     * RN-PAR-03 · Parámetros «por validar» que usó la corrida y que la organización aún no confirmó.
     *
     * @return list<string>
     */
    private function unconfirmedParameters(?string $runId): array
    {
        if ($runId === null) {
            return [];
        }
        $snapshot = json_decode((string) DB::table('payroll_runs')->where('id', $runId)->value('parameters_snapshot'), true);
        $pending = [];
        foreach (is_array($snapshot) ? $snapshot : [] as $param) {
            if (is_array($param) && ($param['pending_validation'] ?? false) === true && ($param['origin'] ?? '') !== 'organization') {
                $pending[(string) $param['code']] = true;
            }
        }
        if ($pending === []) {
            return [];
        }
        $confirmed = DB::table('legal_parameter_confirmations')->whereIn('parameter_code', array_keys($pending))->pluck('parameter_code')->map(fn ($v) => (string) $v)->all();
        $missing = array_values(array_diff(array_keys($pending), $confirmed));
        sort($missing);

        return $missing;
    }

    /**
     * Relaciones laborales del periodo: miembros con vigencia o, sin miembros explícitos, la empresa del grupo.
     *
     * @return list<string>
     */
    public function population(PayrollPeriod $p): array
    {
        $g = PayrollGroup::query()->findOrFail($p->payroll_group_id);
        $from = (string) $p->day('starts_on');
        $to = (string) $p->day('ends_on');
        $query = DB::table('employments')->whereNull('employments.deleted_at')->whereNotIn('employments.status', ['cancelled', 'pending'])
            ->where('employments.hire_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('employments.termination_date')->orWhere('employments.termination_date', '>=', $from));
        $overlap = fn ($q) => $q->where('m.valid_from', '<=', $to)->where(fn ($w) => $w->whereNull('m.valid_to')->orWhere('m.valid_to', '>=', $from));
        if (DB::table('payroll_group_members')->where('payroll_group_id', $g->id)->exists()) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('payroll_group_members as m')
                ->whereColumn('m.employment_id', 'employments.id')->where('m.payroll_group_id', $g->id)->where($overlap));
        } else {
            $query->where('employments.legal_entity_id', $g->legal_entity_id)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('payroll_group_members as m')
                    ->whereColumn('m.employment_id', 'employments.id')->where('m.payroll_group_id', '<>', $g->id)->where($overlap));
        }

        return array_values($query->orderBy('employments.id')->pluck('employments.id')->map(fn ($id): string => (string) $id)->all());
    }

    public function isEditable(PayrollPeriod $p): bool
    {
        return in_array($p->status, self::EDITABLE_STATUSES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PayrollPeriod $p, ?PayrollGroup $g = null): array
    {
        $g ??= PayrollGroup::query()->find($p->payroll_group_id);
        $counts = PayrollIncident::query()->where('payroll_period_id', $p->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return array_filter([
            'id' => $p->id, 'payroll_group_id' => $p->payroll_group_id, 'payroll_group_name' => $g?->name, 'legal_entity_id' => $g?->legal_entity_id,
            'kind' => $p->kind, 'period_year' => $p->period_year, 'period_number' => $p->period_number,
            'starts_on' => $p->day('starts_on'), 'ends_on' => $p->day('ends_on'), 'payment_date' => $p->day('payment_date'),
            'attendance_from' => $p->day('attendance_from'), 'attendance_to' => $p->day('attendance_to'),
            'statutory_period_code' => $p->statutory_period_code, 'status' => $p->status,
            'submitted_at' => $p->submitted_at?->toIso8601String(), 'approved_at' => $p->approved_at?->toIso8601String(),
            'closed_at' => $p->closed_at?->toIso8601String(), 'time_consumed_at' => $p->time_consumed_at?->toIso8601String(),
            'return_reason' => $p->return_reason, 'reopen_reason' => $p->reopen_reason,
            'counts' => ['incidents' => collect(['pending', 'approved', 'applied', 'rejected'])->mapWithKeys(fn ($k) => [$k => (int) ($counts[$k] ?? 0)])->all(),
                'anomalies' => $this->review->openCounts($p->id)],
            'allowed_actions' => array_merge(array_intersect($this->machine->allowedActions($p->status), self::ROUTED_ACTIONS),
                in_array($p->status, self::SIMULATABLE, true) ? ['simulate'] : [], in_array($p->status, self::CLOSED, true) ? ['complementary'] : []),
            'last_run' => $this->lastRunSummary($p),
            'complements_period_id' => $p->complements_period_id, 'paid_at' => $p->paid_at?->toIso8601String(), 'payment_method' => $p->payment_method,
            'approval' => ($approval = $this->runningApproval($p->id)) === null ? null : $this->approvals->summary($approval),
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentGroup(PayrollGroup $g): array
    {
        return array_filter([
            'id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'legal_entity_id' => $g->legal_entity_id,
            'legal_entity_name' => DB::table('legal_entities')->where('id', $g->legal_entity_id)->value('legal_name'),
            'pay_frequency' => $g->pay_frequency, 'labor_regime_code' => $g->labor_regime_code, 'currency' => $g->currency,
            'payment_day' => $g->payment_day, 'cutoff_day' => $g->cutoff_day, 'status' => $g->status,
            'members_count' => DB::table('payroll_group_members')->where('payroll_group_id', $g->id)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))->count(),
        ], fn ($v) => $v !== null);
    }

    /** Periodo anterior del mismo grupo y tipo (el último que empieza antes). */
    public function previous(string $groupId, string $kind, string $startsOn): ?PayrollPeriod
    {
        return PayrollPeriod::query()->where('payroll_group_id', $groupId)->where('kind', $kind)->where('starts_on', '<', $startsOn)
            ->orderByDesc('starts_on')->first();
    }

    private function label(PayrollPeriod $p): string
    {
        return sprintf('%04d-%02d', $p->period_year, $p->period_number);
    }

    /**
     * PER-4 · Última corrida de cálculo vigente del periodo (no simulación ni descartada) con su avance y totales.
     *
     * @return array<string, mixed>|null
     */
    private function lastRunSummary(PayrollPeriod $p): ?array
    {
        $id = DB::table('payroll_runs')->where('payroll_period_id', $p->id)->whereIn('kind', ['calculation', 'recalculation'])
            ->where('status', '<>', 'discarded')->orderByDesc('run_number')->value('id');

        return $id === null ? null : $this->runs->show((string) $id);
    }

    private function lastRun(PayrollPeriod $p): ?string
    {
        $id = DB::table('payroll_runs')->where('payroll_period_id', $p->id)->where('status', '<>', 'discarded')->where('kind', '<>', 'simulation')
            ->orderByDesc('run_number')->value('id');

        return $id === null ? null : (string) $id;
    }

    /** RN-PER-04: no se envía con relaciones con error en el último cálculo. */
    private function assertNoErrors(PayrollPeriod $p): void
    {
        $run = $this->lastRun($p);
        if ($run !== null && DB::table('payroll_run_employments')->where('payroll_run_id', $run)->where('status', 'error')->exists()) {
            throw new AyniException('employments_with_errors', 409, (string) trans('payroll.periods.with_errors'));
        }
    }

    /** RN-PER-01: quien calculó o cargó o modificó incidencias del periodo no lo aprueba. */
    private function assertSegregation(PayrollPeriod $p, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }
        $calculated = DB::table('payroll_runs')->where('payroll_period_id', $p->id)->where('requested_by_user_id', $userId)->exists();
        $touched = DB::table('payroll_incidents')->where('payroll_period_id', $p->id)
            ->where(fn ($q) => $q->where('created_by_user_id', $userId)->orWhere('updated_by_user_id', $userId))->exists();
        if ($calculated || $touched || $p->submitted_by_user_id === $userId) {
            throw new AyniException('segregation_of_duties', 409, (string) trans('payroll.periods.segregation'));
        }
    }

    /** RN-PER-08 · ADR 0017: con declaración presentada o pago registrado, solo ajustes en el periodo siguiente. */
    private function assertNotDeclared(PayrollPeriod $p): void
    {
        $declared = DB::table('statutory_filings')->where('payroll_period_id', $p->id)
            ->whereIn('status', ['submitted', 'accepted', 'observed', 'rectified'])->exists();
        if ($declared) {
            throw new AyniException('period_declared', 409, (string) trans('payroll.periods.declared'));
        }
    }
}
