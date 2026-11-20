<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Engine\CalculationEngine;
use App\Modules\Payroll\Domain\Engine\Explanation;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NOM-8 · Cálculo masivo de un periodo en segundo plano: la corrida se abre en cola con su alcance (todas las
 * relaciones del grupo de planilla, unas relaciones o una unidad) y la procesa el consumidor del evento
 * payroll.run_requested relación por relación, cada una en su propia transacción y con el avance visible.
 *
 * - Una relación con error queda «con error» con su causa y no detiene a las demás (RN-NOM-10).
 * - Reanudar procesa solo las relaciones que faltan: una entrega doble del evento o una caída a mitad no
 *   duplica filas (una fila por corrida y relación, restricción única) y una corrida terminada no se toca.
 * - Mismas entradas → mismo resultado (RN-NOM-02): el orden de proceso es el de los ids y el núcleo no lee
 *   el reloj.
 *
 * NOM-6 · Cada línea guarda su traza; explain() la devuelve en lenguaje claro.
 */
final class PayrollRuns
{
    public const KINDS = ['simulation', 'calculation', 'recalculation'];

    public function __construct(
        private readonly PayrollEngine $engine,
        private readonly OutboxService $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Abre una corrida en cola con la lista de relaciones a calcular ya resuelta (así reanudar no cambia el
     * alcance aunque cambien las altas del grupo después).
     *
     * @param  array{employment_ids?: list<string>|null, org_unit_id?: string|null}  $scope
     */
    public function open(string $periodId, array $scope, string $kind, ?string $userId, bool $queue = true): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new AyniException('validation_failed', 422);
        }
        $period = $this->period($periodId);
        if (in_array((string) $period->status, ['approved', 'closed', 'paid', 'accounted'], true)) {
            // RN-NOM-03 · Un periodo aprobado o cerrado no se recalcula: las diferencias van al periodo abierto (NOM-7).
            throw new AyniException('period_closed', 409, (string) trans('payroll.runs.period_closed'));
        }
        $employments = $this->resolveEmployments($period, $scope);

        return DB::transaction(function () use ($periodId, $scope, $kind, $userId, $queue, $employments): string {
            $tenant = TenancyScope::requireTenant();
            DB::table('payroll_periods')->where('id', $periodId)->lockForUpdate()->first(['id']);
            $number = (int) DB::table('payroll_runs')->where('payroll_period_id', $periodId)->max('run_number') + 1;
            $runId = (string) DB::table('payroll_runs')->insertGetId([
                'tenant_id' => $tenant, 'payroll_period_id' => $periodId, 'run_number' => $number, 'kind' => $kind, 'status' => 'queued',
                'engine_version' => CalculationEngine::VERSION, 'requested_by_user_id' => $userId,
                'scope' => json_encode(['employment_ids' => $scope['employment_ids'] ?? null, 'org_unit_id' => $scope['org_unit_id'] ?? null, 'resolved' => $employments]),
                'employments_total' => count($employments),
            ]);
            $this->audit->record('payroll.run_requested', 'payroll_run', $runId, null, ['period_id' => $periodId, 'kind' => $kind, 'employments' => count($employments)]);
            if ($queue) {
                $this->outbox->record(DomainEventTypes::PAYROLL_RUN_REQUESTED, 'payroll_run', $runId, ['payroll_run_id' => $runId]);
            }

            return $runId;
        });
    }

    /**
     * Procesa lo que falta de la corrida. Idempotente: una corrida terminada o descartada no cambia.
     */
    public function process(string $runId): void
    {
        $run = DB::table('payroll_runs')->where('id', $runId)->first(['id', 'payroll_period_id', 'status', 'scope', 'started_at']);
        if ($run === null || ! in_array((string) $run->status, ['queued', 'running', 'failed'], true)) {
            return;
        }
        $period = $this->period((string) $run->payroll_period_id);
        DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'running', 'failure_reason' => null,
            'started_at' => $run->started_at ?? now(), 'updated_at' => now()]);
        /** @var array{resolved?: list<string>} $scope */
        $scope = json_decode((string) $run->scope, true) ?: [];
        $all = $scope['resolved'] ?? [];
        $done = DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->pluck('employment_id')->map(fn ($v) => (string) $v)->all();
        $pending = array_values(array_diff($all, $done));
        sort($pending);
        $from = substr((string) $period->starts_on, 0, 10);
        $to = substr((string) $period->ends_on, 0, 10);
        try {
            foreach ($pending as $employmentId) {
                $this->processOne($runId, $employmentId, $from, $to, (string) $period->kind, (string) $period->id, (string) $period->currency);
            }
        } catch (Throwable $e) {
            DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            report($e);

            return;
        }
        $this->finish($runId, (string) $period->currency);
    }

    /**
     * Vuelve a poner en cola una corrida que falló o quedó a medias; procesará solo lo que falta.
     */
    public function resume(string $runId): void
    {
        $run = $this->run($runId);
        if (! in_array((string) $run->status, ['failed', 'running', 'queued'], true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.runs.not_resumable'));
        }
        DB::transaction(function () use ($runId): void {
            DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'queued', 'updated_at' => now()]);
            $this->outbox->record(DomainEventTypes::PAYROLL_RUN_REQUESTED, 'payroll_run', $runId, ['payroll_run_id' => $runId, 'resume' => true]);
            $this->audit->record('payroll.run_resumed', 'payroll_run', $runId, null, null);
        });
    }

    /**
     * Descarta una corrida que no se usará (las de simulación o una calculada con datos que cambiaron).
     */
    public function discard(string $runId): void
    {
        $run = $this->run($runId);
        if (in_array((string) $run->status, ['approved', 'closed', 'discarded'], true)) {
            throw new AyniException('invalid_transition', 409);
        }
        DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'discarded', 'updated_at' => now()]);
        $this->audit->record('payroll.run_discarded', 'payroll_run', $runId, ['status' => $run->status], ['status' => 'discarded']);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $runId): array
    {
        $r = $this->run($runId);
        $total = (int) $r->employments_total;
        $processed = (int) $r->employments_done + (int) $r->employments_failed;

        return [
            'id' => (string) $r->id, 'payroll_period_id' => (string) $r->payroll_period_id, 'run_number' => (int) $r->run_number,
            'kind' => (string) $r->kind, 'status' => (string) $r->status, 'engine_version' => $r->engine_version,
            'progress' => ['total' => $total, 'calculated' => (int) $r->employments_done, 'failed' => (int) $r->employments_failed,
                'pending' => max(0, $total - $processed), 'percent' => $total === 0 ? 100 : intdiv($processed * 100, $total)],
            'failure_reason' => $r->failure_reason,
            'started_at' => $r->started_at === null ? null : (string) $r->started_at, 'finished_at' => $r->finished_at === null ? null : (string) $r->finished_at,
            'requested_by_user_id' => $r->requested_by_user_id === null ? null : (string) $r->requested_by_user_id,
            'totals' => $r->totals === null ? null : json_decode((string) $r->totals, true),
            'created_at' => (string) $r->created_at,
        ];
    }

    /**
     * @param  list<string>|null  $ids  ids visibles para quien consulta (alcance), null = todas
     * @return list<array<string, mixed>>
     */
    public function employments(string $runId, ?array $ids = null): array
    {
        $this->run($runId);
        $q = DB::table('payroll_run_employments AS r')->join('employments AS e', 'e.id', '=', 'r.employment_id')
            ->join('people AS p', 'p.id', '=', 'e.person_id')->where('r.payroll_run_id', $runId)
            ->orderBy('p.paternal_surname')->orderBy('p.first_names')->orderBy('r.id');
        if ($ids !== null) {
            $q->whereIn('r.employment_id', $ids);
        }

        return array_values($q->get(['r.id', 'r.employment_id', 'r.status', 'r.gross_amount', 'r.deductions_amount', 'r.net_amount', 'r.employer_cost', 'r.currency', 'r.error',
            'e.employee_code', 'e.person_id', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname'])
            ->map(fn ($r) => [
                'id' => (string) $r->id, 'employment_id' => (string) $r->employment_id, 'person_id' => (string) $r->person_id, 'employee_code' => $r->employee_code,
                'employee_name' => trim($r->first_names.' '.$r->paternal_surname.' '.($r->maternal_surname ?? '')),
                'status' => (string) $r->status,
                'errors' => $r->error === null ? [] : (json_decode((string) $r->error, true) ?: [['code' => 'error', 'message' => (string) $r->error]]),
                'totals' => $r->status === 'calculated' ? [
                    'earnings' => ['amount' => (string) $r->gross_amount, 'currency' => (string) $r->currency],
                    'deductions' => ['amount' => (string) $r->deductions_amount, 'currency' => (string) $r->currency],
                    'net' => ['amount' => (string) $r->net_amount, 'currency' => (string) $r->currency],
                    'employer_cost' => ['amount' => (string) $r->employer_cost, 'currency' => (string) $r->currency],
                ] : null,
            ])->all());
    }

    /**
     * Líneas de una relación en una corrida, cada una con su explicación (NOM-6).
     *
     * @return array{run_employment: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function lines(string $runEmploymentId): array
    {
        $row = DB::table('payroll_run_employments')->where('id', $runEmploymentId)->first(['id', 'payroll_run_id', 'employment_id', 'status', 'context_snapshot']);
        if ($row === null) {
            throw new AyniException('not_found', 404);
        }
        $lines = DB::table('payroll_run_lines AS l')->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')
            ->where('l.payroll_run_employment_id', $runEmploymentId)->orderBy('l.created_at')->orderBy('l.id')
            ->get(['l.id', 'l.amount', 'l.currency', 'l.origin', 'l.trace', 'l.payroll_concept_version_id', 'l.payroll_incident_id', 'c.code', 'c.name', 'c.kind']);

        return [
            'run_employment' => ['id' => (string) $row->id, 'payroll_run_id' => (string) $row->payroll_run_id, 'employment_id' => (string) $row->employment_id, 'status' => (string) $row->status],
            'lines' => array_values($lines->map(fn ($l) => $this->presentLine($l))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(string $lineId): array
    {
        $l = DB::table('payroll_run_lines AS l')->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')
            ->join('payroll_run_employments AS r', 'r.id', '=', 'l.payroll_run_employment_id')
            ->where('l.id', $lineId)
            ->first(['l.id', 'l.amount', 'l.currency', 'l.origin', 'l.trace', 'l.payroll_concept_version_id', 'l.payroll_incident_id', 'l.payroll_run_id', 'r.employment_id', 'c.code', 'c.name', 'c.kind']);
        if ($l === null) {
            throw new AyniException('not_found', 404);
        }

        return $this->presentLine($l) + ['payroll_run_id' => (string) $l->payroll_run_id, 'employment_id' => (string) $l->employment_id];
    }

    public function employmentOfLine(string $lineId): ?string
    {
        $v = DB::table('payroll_run_lines AS l')->join('payroll_run_employments AS r', 'r.id', '=', 'l.payroll_run_employment_id')
            ->where('l.id', $lineId)->value('r.employment_id');

        return $v === null ? null : (string) $v;
    }

    public function employmentOfRow(string $runEmploymentId): ?string
    {
        $v = DB::table('payroll_run_employments')->where('id', $runEmploymentId)->value('employment_id');

        return $v === null ? null : (string) $v;
    }

    /**
     * @param  \stdClass  $l
     * @return array<string, mixed>
     */
    private function presentLine(object $l): array
    {
        /** @var array<string, mixed> $trace */
        $trace = json_decode((string) $l->trace, true) ?: [];
        $line = ['concept_code' => (string) $l->code, 'concept_name' => (string) $l->name, 'kind' => (string) $l->kind,
            'concept_version_id' => $l->payroll_concept_version_id === null ? null : (string) $l->payroll_concept_version_id,
            'amount' => ['amount' => (string) $l->amount, 'currency' => (string) $l->currency], 'trace' => $trace, 'origin' => (string) $l->origin];

        return [
            'id' => (string) $l->id, 'concept_code' => (string) $l->code, 'concept_name' => (string) $l->name, 'kind' => (string) $l->kind,
            'origin' => (string) $l->origin, 'amount' => $line['amount'],
            'payroll_incident_id' => $l->payroll_incident_id === null ? null : (string) $l->payroll_incident_id,
            'explanation' => Explanation::of($line),
        ];
    }

    private function processOne(string $runId, string $employmentId, string $from, string $to, string $periodKind, string $periodId, string $groupCurrency): void
    {
        $tenant = TenancyScope::requireTenant();
        $failure = [];
        try {
            $calc = $this->engine->calculate($employmentId, $from, $to, $periodKind, $periodId);
        } catch (Throwable $e) {
            // RN-NOM-10 · Un error inesperado en una relación tampoco detiene a las demás.
            report($e);
            $calc = null;
            $failure = [['code' => 'unexpected', 'message' => (string) trans('payroll.runs.unexpected')]];
        }
        DB::transaction(function () use ($runId, $employmentId, $tenant, $calc, $groupCurrency, &$failure): void {
            if (DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->where('employment_id', $employmentId)->lockForUpdate()->exists()) {
                return;
            }
            $r = $calc['result'] ?? null;
            $status = $r === null ? 'error' : ($r['status'] === 'calculated' ? 'calculated' : ($r['status'] === 'excluded' ? 'excluded' : 'error'));
            $ok = $status === 'calculated';
            $currency = $calc !== null && ($calc['context']['currency'] ?? '') !== '' ? (string) $calc['context']['currency'] : $groupCurrency;
            $rowId = (string) DB::table('payroll_run_employments')->insertGetId([
                'tenant_id' => $tenant, 'payroll_run_id' => $runId, 'employment_id' => $employmentId, 'status' => $status,
                'gross_amount' => $ok ? $r['totals']['earnings'] : null, 'deductions_amount' => $ok ? $r['totals']['deductions'] : null,
                'net_amount' => $ok ? $r['totals']['net'] : null, 'employer_cost' => $ok ? $r['totals']['employer_cost'] : null,
                'currency' => $currency,
                'worked_days' => $calc['context']['variables']['dias_laborados'] ?? null,
                'context_snapshot' => $calc === null ? null : json_encode($calc['context'] + ['warnings' => $r['warnings'], 'order' => $r['order'], 'strategy' => $r['strategy'] ?? null]),
                'error' => $ok ? null : json_encode($r === null ? $failure : ($r['status'] === 'excluded' ? $r['warnings'] : $r['errors'])),
            ]);
            if ($ok) {
                foreach ($r['lines'] as $line) {
                    DB::table('payroll_run_lines')->insert([
                        'tenant_id' => $tenant, 'payroll_run_id' => $runId, 'payroll_run_employment_id' => $rowId,
                        'payroll_concept_id' => $line['concept_id'], 'payroll_concept_version_id' => $line['concept_version_id'],
                        'amount' => $line['amount']['amount'], 'currency' => $line['amount']['currency'], 'origin' => $line['origin'] ?? 'calculated',
                        'payroll_incident_id' => $line['incident_id'] ?? null, 'trace' => json_encode($line['trace']),
                    ]);
                }
            }
            DB::table('payroll_runs')->where('id', $runId)->update([
                $status === 'error' ? 'employments_failed' : 'employments_done' => DB::raw(($status === 'error' ? 'employments_failed' : 'employments_done').' + 1'),
                'updated_at' => now(),
            ]);
        });
    }

    private function finish(string $runId, string $currency): void
    {
        $parameters = [];
        foreach (DB::table('payroll_run_lines')->where('payroll_run_id', $runId)->orderBy('id')->pluck('trace') as $t) {
            $trace = json_decode((string) $t, true) ?: [];
            foreach ((array) ($trace['parameters'] ?? []) as $p) {
                $parameters[$p['code'].'@'.$p['valid_from']] = $p;
            }
            foreach ((array) ($trace['tramos'] ?? []) as $tramo) {
                foreach ((array) ($tramo['trace']['parameters'] ?? []) as $p) {
                    $parameters[$p['code'].'@'.$p['valid_from']] = $p;
                }
            }
        }
        foreach (DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->whereNotNull('context_snapshot')->pluck('context_snapshot') as $c) {
            $p = (json_decode((string) $c, true) ?: [])['sources']['proration_parameter'] ?? null;
            if (is_array($p)) {
                $parameters[$p['code'].'@'.$p['valid_from']] = $p;
            }
        }
        ksort($parameters);
        $sum = fn (string $col): string => (string) BigDecimal::of((string) (DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->where('status', 'calculated')->sum($col) ?: '0'));
        $totals = ['earnings' => $sum('gross_amount'), 'deductions' => $sum('deductions_amount'), 'net' => $sum('net_amount'), 'employer_cost' => $sum('employer_cost'), 'currency' => $currency];
        DB::transaction(function () use ($runId, $parameters, $totals, $currency): void {
            $kind = DB::table('payroll_runs')->where('id', $runId)->value('kind');
            DB::table('payroll_runs')->where('id', $runId)->update([
                'status' => 'completed', 'finished_at' => now(), 'updated_at' => now(), 'parameters_snapshot' => json_encode(array_values($parameters)),
                'totals' => json_encode($totals),
            ]);
            if ($kind !== 'simulation') {
                $this->outbox->record(DomainEventTypes::PAYROLL_CALCULATED, 'payroll_run', $runId, ['payroll_run_id' => $runId, 'total_net' => $totals['net'], 'currency' => $currency]);
            }
        });
    }

    /**
     * @param  array{employment_ids?: list<string>|null, org_unit_id?: string|null}  $scope
     * @return list<string>
     */
    private function resolveEmployments(\stdClass $period, array $scope): array
    {
        $from = substr((string) $period->starts_on, 0, 10);
        $to = substr((string) $period->ends_on, 0, 10);
        $q = DB::table('employments AS e')->whereNull('e.deleted_at')->where('e.hire_date', '<=', $to)
            ->where(fn ($w) => $w->whereNull('e.termination_date')->orWhere('e.termination_date', '>=', $from));
        $members = DB::table('payroll_group_members')->where('payroll_group_id', $period->payroll_group_id)
            ->where('valid_from', '<=', $to)->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $from));
        if ($members->exists()) {
            $q->whereIn('e.id', $members->select('employment_id'));
        } else {
            // Sin pertenencias registradas, el grupo abarca a la empresa del grupo.
            $q->where('e.legal_entity_id', $period->legal_entity_id);
        }
        if (! empty($scope['employment_ids'])) {
            $q->whereIn('e.id', $scope['employment_ids']);
        }
        if (! empty($scope['org_unit_id'])) {
            $q->whereExists(fn ($x) => $x->from('employment_assignments AS a')->whereColumn('a.employment_id', 'e.id')->whereNull('a.deleted_at')
                ->where('a.org_unit_id', $scope['org_unit_id'])->where('a.valid_from', '<=', $to)
                ->where(fn ($w) => $w->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $to)));
        }
        $ids = $q->orderBy('e.id')->pluck('e.id')->map(fn ($v) => (string) $v)->all();

        return array_values($ids);
    }

    private function period(string $periodId): \stdClass
    {
        /** @var \stdClass|null $p */
        $p = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')
            ->where('p.id', $periodId)->first(['p.id', 'p.payroll_group_id', 'p.starts_on', 'p.ends_on', 'p.kind', 'p.status', 'g.currency', 'g.legal_entity_id']);
        if ($p === null) {
            throw new AyniException('not_found', 404);
        }

        return $p;
    }

    private function run(string $runId): \stdClass
    {
        /** @var \stdClass|null $r */
        $r = DB::table('payroll_runs')->where('id', $runId)->first();
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }

        return $r;
    }
}
