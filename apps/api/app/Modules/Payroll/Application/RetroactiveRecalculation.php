<?php

namespace App\Modules\Payroll\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * NOM-7 · Recálculo retroactivo (C-03, RN-NOM-03, ADR 0017): cuando un cambio con fecha pasada afecta
 * periodos ya cerrados, el motor recalcula esos periodos «en sombra» con los datos de hoy, obtiene la
 * diferencia por concepto contra lo que se pagó y la deja como incidencia retroactiva (reintegro si es a
 * favor, descuento si es en contra) en el primer periodo abierto del mismo grupo, con el periodo de origen.
 *
 * - Un periodo cerrado nunca se modifica: no se tocan sus corridas ni sus líneas.
 * - Repetir el recálculo con los mismos datos deja las mismas incidencias (se actualiza la pendiente del
 *   mismo origen y concepto; si la diferencia desaparece, la pendiente se retira).
 * - Lo dispara el evento employment.retroactive_change (HIS-4) o RRHH a mano.
 */
final class RetroactiveRecalculation
{
    private const CLOSED = ['approved', 'closed', 'paid', 'accounted'];

    private const OPEN = ['open', 'incidents_closed', 'calculated', 'in_review'];

    public function __construct(private readonly PayrollEngine $engine, private readonly AuditLogger $audit) {}

    /**
     * @return list<array<string, mixed>> un elemento por periodo cerrado afectado
     */
    public function recalculate(string $employmentId, string $from, ?string $to, bool $preview, ?string $reason): array
    {
        $out = [];
        foreach ($this->closedPeriods($employmentId, $from, $to) as $closed) {
            $paid = $this->paidAmounts((string) $closed->run_employment_id);
            $start = substr((string) $closed->starts_on, 0, 10);
            $end = substr((string) $closed->ends_on, 0, 10);
            $calc = $this->engine->calculate($employmentId, $start, $end, (string) $closed->kind, (string) $closed->id);
            $label = sprintf('%04d-%02d', (int) $closed->period_year, (int) $closed->period_number);
            $item = ['period_id' => (string) $closed->id, 'period' => $label, 'from' => $start, 'to' => $end, 'status' => 'recalculated',
                'target_period_id' => null, 'differences' => [], 'errors' => []];
            if ($calc['result']['status'] !== 'calculated') {
                $item['status'] = 'error';
                $item['errors'] = $calc['result']['errors'] ?: $calc['result']['warnings'];
                $out[] = $item;

                continue;
            }
            $now = [];
            $ids = [];
            foreach ($calc['result']['lines'] as $line) {
                if (($line['origin'] ?? 'calculated') === 'retroactive') {
                    continue;
                }
                $now[$line['concept_code']] = BigDecimal::of($line['amount']['amount']);
                $ids[$line['concept_code']] = (string) $line['concept_id'];
            }
            foreach ($paid as $code => $p) {
                $ids[$code] ??= $p['concept_id'];
            }
            $codes = array_unique(array_merge(array_keys($now), array_keys($paid)));
            sort($codes);
            foreach ($codes as $code) {
                $before = isset($paid[$code]) ? BigDecimal::of($paid[$code]['amount']) : BigDecimal::zero();
                $after = $now[$code] ?? BigDecimal::zero();
                $diff = $after->minus($before);
                $item['differences'][] = ['concept_code' => $code, 'concept_id' => $ids[$code], 'paid' => (string) $before->toScale(2),
                    'recalculated' => (string) $after->toScale(2), 'difference' => (string) $diff->toScale(2)];
            }
            $target = $this->openPeriod((string) $closed->payroll_group_id, $end);
            $item['target_period_id'] = $target;
            if ($target === null) {
                $item['status'] = 'no_open_period';
            } elseif (! $preview) {
                $this->apply($employmentId, (string) $closed->id, $target, $label, $item['differences'], (string) $closed->currency, $reason);
            }
            $item['differences'] = array_values(array_filter($item['differences'], fn ($d) => ! BigDecimal::of($d['difference'])->isZero()));
            foreach ($item['differences'] as &$d) {
                unset($d['concept_id']);
            }
            unset($d);
            $out[] = $item;
        }
        if (! $preview) {
            $this->audit->record('payroll.retroactive_recalculated', 'employment', $employmentId, null,
                ['from' => $from, 'to' => $to, 'periods' => array_map(fn ($i) => ['period' => $i['period'], 'status' => $i['status'], 'differences' => count($i['differences'])], $out)], $reason);
        }

        return $out;
    }

    /**
     * @param  list<array{concept_code: string, concept_id: string, difference: string}>  $differences
     */
    private function apply(string $employmentId, string $originId, string $targetId, string $label, array $differences, string $currency, ?string $reason): void
    {
        DB::transaction(function () use ($employmentId, $originId, $targetId, $label, $differences, $currency, $reason): void {
            $tenant = TenancyScope::requireTenant();
            foreach ($differences as $d) {
                $amount = BigDecimal::of($d['difference']);
                $existing = DB::table('payroll_incidents')->where('payroll_period_id', $targetId)->where('employment_id', $employmentId)
                    ->where('payroll_concept_id', $d['concept_id'])->where('retro_period_id', $originId)->where('source', 'retroactive')
                    ->where('status', 'pending')->whereNull('deleted_at')->lockForUpdate()->first(['id', 'amount']);
                $notes = (string) trans('payroll.retroactive.note', ['period' => $label]).($reason !== null && $reason !== '' ? ' · '.$reason : '');
                if ($existing !== null) {
                    if ($amount->isZero()) {
                        DB::table('payroll_incidents')->where('id', $existing->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                    } elseif (! BigDecimal::of((string) $existing->amount)->isEqualTo($amount)) {
                        DB::table('payroll_incidents')->where('id', $existing->id)->update(['amount' => (string) $amount, 'notes' => $notes, 'updated_at' => now(),
                            'lock_version' => DB::raw('lock_version + 1')]);
                    }

                    continue;
                }
                if ($amount->isZero()) {
                    continue;
                }
                DB::table('payroll_incidents')->insert([
                    'tenant_id' => $tenant, 'payroll_period_id' => $targetId, 'employment_id' => $employmentId, 'payroll_concept_id' => $d['concept_id'],
                    'amount' => (string) $amount, 'currency' => $currency, 'source' => 'retroactive', 'source_type' => 'payroll_period', 'source_id' => $originId,
                    'retro_period_id' => $originId, 'notes' => $notes, 'status' => 'pending',
                ]);
            }
        });
    }

    /**
     * Periodos cerrados del rango con una corrida de cálculo de la relación (la última aprobada, cerrada o completa).
     *
     * @return list<\stdClass>
     */
    private function closedPeriods(string $employmentId, string $from, ?string $to): array
    {
        $q = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')
            ->join('payroll_runs AS r', 'r.payroll_period_id', '=', 'p.id')
            ->join('payroll_run_employments AS re', fn ($j) => $j->on('re.payroll_run_id', '=', 'r.id')->where('re.employment_id', '=', $employmentId))
            ->whereIn('p.status', self::CLOSED)->where('p.ends_on', '>=', $from)
            ->whereIn('r.status', ['approved', 'closed', 'completed'])->whereIn('r.kind', ['calculation', 'recalculation'])->where('re.status', 'calculated');
        if ($to !== null) {
            $q->where('p.starts_on', '<=', $to);
        }
        $rows = $q->orderBy('p.starts_on')->orderBy('p.id')->orderByDesc('r.run_number')
            ->get(['p.id', 'p.payroll_group_id', 'p.kind', 'p.starts_on', 'p.ends_on', 'p.period_year', 'p.period_number', 'g.currency', 're.id AS run_employment_id']);
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->id])) {
                continue;
            }
            $seen[$r->id] = true;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @return array<string, array{amount: string, concept_id: string}>
     */
    private function paidAmounts(string $runEmploymentId): array
    {
        $out = [];
        $rows = DB::table('payroll_run_lines AS l')->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')
            ->where('l.payroll_run_employment_id', $runEmploymentId)->where('l.origin', '<>', 'retroactive')
            ->get(['c.code', 'c.id', 'l.amount']);
        foreach ($rows as $r) {
            $cur = $out[(string) $r->code]['amount'] ?? '0';
            $out[(string) $r->code] = ['amount' => (string) BigDecimal::of($cur)->plus(BigDecimal::of((string) $r->amount)), 'concept_id' => (string) $r->id];
        }

        return $out;
    }

    private function openPeriod(string $groupId, string $after): ?string
    {
        $id = DB::table('payroll_periods')->where('payroll_group_id', $groupId)->where('kind', 'regular')->whereIn('status', self::OPEN)
            ->where('starts_on', '>', $after)->orderBy('starts_on')->value('id');

        return $id === null ? null : (string) $id;
    }
}
