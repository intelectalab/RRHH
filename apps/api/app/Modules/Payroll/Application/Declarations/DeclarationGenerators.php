<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Modules\Payroll\Application\PayrollConcepts;
use App\Modules\Payroll\Domain\Declarations\FileSerializer;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DEC-2 · DEC-3 · DEC-4 · Arma las filas de cada estructura oficial y las escribe con FileSerializer.
 *
 * Devuelve, por declaración: los archivos (estructura, contenido, líneas y totales de control), los errores por
 * trabajador que impiden presentar (RN-DEC-02) y los avisos que no la impiden. Ninguna regla legal vive aquí: las
 * estructuras, los códigos y los plazos son datos del paquete de país y parámetros con vigencia.
 */
final class DeclarationGenerators
{
    private const EARNINGS = ['earning', 'benefit'];

    private const DEDUCTIONS = ['deduction', 'employee_contribution', 'tax'];

    /** Conceptos que no van a la PLAME: provisiones e informativos. */
    private const NOT_DECLARED = ['provision', 'informative'];

    public const GENERATOR_VERSION = 'dec-2026.12';

    public function __construct(
        private readonly DeclarationSources $sources,
        private readonly PayrollConcepts $concepts,
    ) {}

    /**
     * @param  array{legal_entity_id: string, kind: string, period_code: string, payroll_period_id: string|null}  $f
     * @return array{files: list<array<string, mixed>>, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, draft_only: bool, draft_reasons: list<string>, totals: array<string, mixed>, events?: list<string>}
     */
    public function build(array $f): array
    {
        $entity = DB::table('legal_entities')->where('id', $f['legal_entity_id'])->first(['tax_id']);
        $ruc = (string) ($entity->tax_id ?? '');
        [$from, $to] = $this->periodRange($f);
        $layouts = $this->layouts($f['kind'] === 'afpnet' ? 'afpnet' : $f['kind'], $to);
        /** @var array{files: list<array<string, mixed>>, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, draft_only: bool, draft_reasons: list<string>, totals: array<string, mixed>, events?: list<string>} $out */
        $out = ['files' => [], 'errors' => [], 'warnings' => [], 'draft_only' => false, 'draft_reasons' => [], 'totals' => []];
        if ($layouts === []) {
            $out['errors'][] = ['ref' => null, 'field' => 'layout', 'rule' => 'layout_missing'];

            return $out;
        }
        $rows = match ($f['kind']) {
            'plame' => $this->plameRows($f, $from, $to, $out),
            'afpnet' => $this->afpnetRows($f, $from, $to, $out),
            default => $this->tregistroRows($f, $to, $out),
        };
        foreach ($layouts as $layout) {
            $structure = (string) $layout->structure_code;
            $set = $rows[$structure] ?? [];
            if ($set === []) {
                continue;
            }
            /** @var list<array<string, mixed>> $fields */
            $fields = json_decode((string) $layout->fields, true);
            $written = FileSerializer::serialize([
                'field_separator' => $layout->field_separator === null ? null : (string) $layout->field_separator,
                'trailing_separator' => (bool) $layout->trailing_separator, 'line_ending' => (string) $layout->line_ending,
                'encoding' => (string) $layout->encoding, 'fields' => $fields,
            ], $set);
            foreach ($written['errors'] as $e) {
                $item = ['ref' => $e['ref'], 'structure' => $structure, 'field' => $e['field'], 'rule' => $e['rule'], 'row' => $e['row']];
                $out['errors'][] = $item;
            }
            if (! (bool) $layout->verified) {
                $out['draft_only'] = true;
                $out['draft_reasons'][] = 'layout_unverified:'.$structure;
            }
            $out['files'][] = [
                'file_kind' => (string) ($layout->file_extension ?? $structure), 'layout_id' => (string) $layout->id, 'structure_code' => $structure,
                'layout_version' => (string) $layout->layout_version,
                'file_name' => FileSerializer::fileName((string) $layout->file_name_pattern, $ruc, $f['period_code'], (string) ($layout->file_extension ?? 'txt')),
                'content' => $written['content'], 'record_count' => $written['lines'], 'sha256' => hash('sha256', $written['content']),
                'byte_size' => strlen($written['content']),
            ];
        }
        $out['draft_reasons'] = array_values(array_unique($out['draft_reasons']));

        return $out;
    }

    /**
     * @param  array{legal_entity_id: string, kind: string, period_code: string, payroll_period_id: string|null}  $f
     * @return array{0: string, 1: string}
     */
    public function periodRange(array $f): array
    {
        if ($f['payroll_period_id'] !== null) {
            $p = DB::table('payroll_periods')->where('id', $f['payroll_period_id'])->first(['starts_on', 'ends_on']);
            if ($p !== null) {
                return [substr((string) $p->starts_on, 0, 10), substr((string) $p->ends_on, 0, 10)];
            }
        }
        $start = CarbonImmutable::createFromFormat('Ym', $f['period_code'])?->startOfMonth() ?? CarbonImmutable::now()->startOfMonth();

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    /** @return list<\stdClass> */
    private function layouts(string $kind, string $on): array
    {
        $rows = DB::table('statutory_file_layouts')->where('country_code', 'PE')->where('filing_kind', $kind)->where('valid_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $on))->orderBy('structure_code')->orderByDesc('valid_from')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->structure_code] ??= $r;
        }

        return array_values($out);
    }

    /** Corrida cerrada vigente del periodo (la de mayor número). */
    public function closedRun(?string $periodId): ?string
    {
        if ($periodId === null) {
            return null;
        }
        $id = DB::table('payroll_runs')->where('payroll_period_id', $periodId)->where('status', 'closed')->orderByDesc('run_number')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * DEC-1 · Conceptos usados (con monto) en la corrida que no tienen código de la declaración vigente a la fecha
     * o cuyo código no existe en la Tabla 22 a esa fecha (RN-DEC-06, RN-DEC-13).
     *
     * @return list<array{payroll_concept_id: string, code: string, name: string, statutory_code: string|null, rule: string}>
     */
    public function unmappedConcepts(string $runId, string $on): array
    {
        $used = DB::table('payroll_run_lines AS l')->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')
            ->where('l.payroll_run_id', $runId)->whereNotIn('c.kind', self::NOT_DECLARED)->where('l.amount', '<>', 0)
            ->distinct()->orderBy('c.code')->get(['c.id', 'c.code', 'c.name']);
        $out = [];
        foreach ($used as $c) {
            $m = $this->mapping((string) $c->id, $on);
            if ($m === null) {
                $out[] = ['payroll_concept_id' => (string) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'statutory_code' => null, 'rule' => 'unmapped'];
            } elseif (! $this->concepts->statutoryCodeExists((string) $m->statutory_code, $on)) {
                $out[] = ['payroll_concept_id' => (string) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'statutory_code' => (string) $m->statutory_code, 'rule' => 'not_in_table_22'];
            }
        }

        return $out;
    }

    private function mapping(string $conceptId, string $on): ?\stdClass
    {
        return DB::table('concept_statutory_mappings')->where('payroll_concept_id', $conceptId)->where('filing_kind', 'plame')
            ->where('valid_from', '<=', $on)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $on))
            ->orderByDesc('valid_from')->first();
    }

    /**
     * @param  array{legal_entity_id: string, kind: string, period_code: string, payroll_period_id: string|null}  $f
     * @param  array{files: list<array<string, mixed>>, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, draft_only: bool, draft_reasons: list<string>, totals: array<string, mixed>, events?: list<string>}  $out
     * @return array<int|string, list<array<string, mixed>>>
     */
    private function plameRows(array $f, string $from, string $to, array &$out): array
    {
        $runId = $this->closedRun($f['payroll_period_id']);
        if ($runId === null) {
            $out['errors'][] = ['ref' => null, 'field' => 'payroll_period_id', 'rule' => 'period_not_closed'];

            return [];
        }
        foreach ($this->unmappedConcepts($runId, $to) as $u) {
            $out['errors'][] = ['ref' => null, 'field' => 'concept:'.$u['code'], 'rule' => $u['rule'], 'payroll_concept_id' => $u['payroll_concept_id'], 'concept_name' => $u['name']];
        }
        $lines = DB::table('payroll_run_lines AS l')->join('payroll_run_employments AS re', 're.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')->where('l.payroll_run_id', $runId)->where('re.status', 'calculated')
            ->orderBy('re.employment_id')->orderBy('c.code')->get(['re.employment_id', 'c.id AS concept_id', 'c.kind', 'l.amount']);
        $workers = [];
        $rem = [];
        $file = ['earnings' => BigDecimal::zero(), 'deductions' => BigDecimal::zero(), 'employer' => BigDecimal::zero()];
        $sums = [];
        $mappings = [];
        foreach ($lines as $l) {
            $emp = (string) $l->employment_id;
            $workers[$emp] ??= $this->sources->worker($emp);
            if (in_array((string) $l->kind, self::NOT_DECLARED, true)) {
                continue;
            }
            $cid = (string) $l->concept_id;
            $mappings[$cid] ??= $this->mapping($cid, $to) ?? false;
            $m = $mappings[$cid];
            if ($m === false) {
                continue;
            }
            $key = $emp.'|'.$m->statutory_code;
            $sums[$key] ??= ['emp' => $emp, 'code' => (string) $m->statutory_code, 'accrued' => BigDecimal::zero(), 'paid' => BigDecimal::zero()];
            $amount = BigDecimal::of((string) $l->amount);
            $role = (string) $m->amount_role;
            if ($role !== 'paid_only') {
                $sums[$key]['accrued'] = $sums[$key]['accrued']->plus($amount);
            }
            if ($role !== 'accrued_only') {
                $sums[$key]['paid'] = $sums[$key]['paid']->plus($amount);
            }
            $group = in_array((string) $l->kind, self::EARNINGS, true) ? 'earnings' : (in_array((string) $l->kind, self::DEDUCTIONS, true) ? 'deductions' : 'employer');
            $file[$group] = $file[$group]->plus($amount);
        }
        foreach ($sums as $s) {
            $rem[] = array_merge($workers[$s['emp']], ['concept.statutory_code' => $s['code'], 'line.accrued' => (string) $s['accrued']->toScale(2), 'line.paid' => (string) $s['paid']->toScale(2)]);
        }
        $toc = [];
        foreach ($workers as $emp => $w) {
            $profile = $this->sources->profile($emp, $to);
            if ($profile === null) {
                $out['errors'][] = ['ref' => $emp, 'field' => 'registry_profile', 'rule' => 'missing'];

                continue;
            }
            $toc[] = array_merge($w, $profile['values']);
        }
        $snl = $this->suspensions(array_keys($workers), $from, $to, $workers);
        // Los totales del archivo cuadran con la corrida cerrada (RN-DEC-02 · AR-05).
        $run = DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->where('status', 'calculated')
            ->selectRaw('COALESCE(sum(gross_amount),0) AS g, COALESCE(sum(deductions_amount),0) AS d, COALESCE(sum(employer_cost),0) - COALESCE(sum(gross_amount),0) AS e')->first();
        $payroll = ['earnings' => BigDecimal::of((string) ($run->g ?? '0')), 'deductions' => BigDecimal::of((string) ($run->d ?? '0')), 'employer' => BigDecimal::of((string) ($run->e ?? '0'))];
        $matches = $file['earnings']->isEqualTo($payroll['earnings']) && $file['deductions']->isEqualTo($payroll['deductions']);
        $out['totals'] = [
            'workers' => count($workers), 'matches_payroll' => $matches,
            'file' => array_map(fn (BigDecimal $v): string => (string) $v->toScale(2), $file),
            'payroll' => array_map(fn (BigDecimal $v): string => (string) $v->toScale(2), $payroll),
        ];
        if (! $matches) {
            $out['errors'][] = ['ref' => null, 'field' => 'totals', 'rule' => 'totals_mismatch'];
        }
        if (DB::table('payroll_periods')->where('id', $f['payroll_period_id'])->whereNotIn('status', ['closed', 'paid', 'accounted'])->exists()) {
            $out['draft_only'] = true;
            $out['draft_reasons'][] = 'period_not_closed';
        }
        // La jornada (.jor) sale del módulo de asistencia; sin él no se arma y se avisa.
        $out['warnings'][] = ['ref' => null, 'structure' => 'jor', 'code' => 'working_time_pending'];

        return ['18' => $rem, '15' => $snl, '26' => $toc];
    }

    /**
     * Días subsidiados y no laborados del periodo, de las ausencias registradas cuyo tipo tiene código de suspensión.
     *
     * @param  list<string>  $employments
     * @param  array<string, array<string, mixed>>  $workers
     * @return list<array<string, mixed>>
     */
    private function suspensions(array $employments, string $from, string $to, array $workers): array
    {
        if ($employments === []) {
            return [];
        }
        $rows = DB::table('absence_requests AS a')->join('absence_type_rules AS t', fn ($j) => $j->on('t.absence_type_id', '=', 'a.absence_type_id')->where('t.valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('t.valid_to')->orWhere('t.valid_to', '>=', $to)))
            ->whereIn('a.employment_id', $employments)->whereIn('a.status', ['approved', 'in_progress', 'completed', 'pending_document'])
            ->whereNotNull('t.statutory_suspension_code')->whereNotNull('a.starts_on')
            ->where('a.starts_on', '<=', $to)->where('a.ends_on', '>=', $from)
            ->orderBy('a.employment_id')->get(['a.employment_id', 'a.starts_on', 'a.ends_on', 't.statutory_suspension_code']);
        $days = [];
        foreach ($rows as $r) {
            $start = max(substr((string) $r->starts_on, 0, 10), $from);
            $end = min(substr((string) $r->ends_on, 0, 10), $to);
            $n = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
            $key = $r->employment_id.'|'.$r->statutory_suspension_code;
            $days[$key] = ['emp' => (string) $r->employment_id, 'code' => (string) $r->statutory_suspension_code, 'days' => ($days[$key]['days'] ?? 0) + $n];
        }
        $out = [];
        foreach ($days as $d) {
            $out[] = array_merge($workers[$d['emp']] ?? $this->sources->worker($d['emp']), ['absence.statutory_suspension_code' => $d['code'], 'absence.days' => (string) $d['days']]);
        }

        return $out;
    }

    /**
     * @param  array{legal_entity_id: string, kind: string, period_code: string, payroll_period_id: string|null}  $f
     * @param  array{files: list<array<string, mixed>>, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, draft_only: bool, draft_reasons: list<string>, totals: array<string, mixed>, events?: list<string>}  $out
     * @return array<int|string, list<array<string, mixed>>>
     */
    private function afpnetRows(array $f, string $from, string $to, array &$out): array
    {
        $runId = $this->closedRun($f['payroll_period_id']);
        if ($runId === null) {
            $out['errors'][] = ['ref' => null, 'field' => 'payroll_period_id', 'rule' => 'period_not_closed'];

            return [];
        }
        $employments = DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->where('status', 'calculated')
            ->orderBy('employment_id')->pluck('employment_id')->map(fn ($v): string => (string) $v)->all();
        $rows = [];
        $seq = 0;
        $insurableTotal = BigDecimal::zero();
        foreach ($employments as $emp) {
            $pension = $this->sources->pension($emp, $to);
            if ($pension === null || $pension['system'] !== 'afp') {
                continue;
            }
            $w = $this->sources->worker($emp);
            if ($pension['cuspp'] === null || trim($pension['cuspp']) === '') {
                // Sin código de afiliado (CUSPP): la línea sale y el trabajador se reporta para completarlo.
                $out['warnings'][] = ['ref' => $emp, 'structure' => 'planilla', 'field' => 'cuspp', 'code' => 'missing_affiliate_code'];
            }
            $insurable = $this->baseAmount($runId, $emp, 'pension');
            $insurableTotal = $insurableTotal->plus($insurable);
            $hire = (string) $w['_hire_date'];
            $end = $w['_termination_date'];
            $exception = '';
            if ($insurable->isZero()) {
                $exception = (string) $this->sources->code('excepcion_afpnet', $this->exceptionReason($emp, $from, $to));
            }
            $regime = DB::table('employment_labor_regimes')->where('employment_id', $emp)->where('valid_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $to))->orderByDesc('valid_from')->value('labor_regime_code');
            $rows[] = array_merge($w, [
                'seq' => (string) ++$seq, 'pension.cuspp' => $pension['cuspp'], 'pension.afpnet_code' => $pension['afpnet_code'],
                'afp.relation' => 'S', 'afp.start' => $hire >= $from && $hire <= $to ? 'S' : 'N',
                'afp.end' => is_string($end) && $end >= $from && $end <= $to ? 'S' : 'N', 'afp.exception' => $exception,
                'afp.insurable' => (string) $insurable->toScale(2), 'afp.voluntary_pension' => '0', 'afp.voluntary_other' => '0', 'afp.voluntary_employer' => '0',
                'afp.work_type' => $this->sources->code('tipo_trabajo_afpnet', is_string($regime) ? $regime : null) ?? $this->sources->code('tipo_trabajo_afpnet', '*'),
            ]);
        }
        $out['totals'] = ['workers' => count($rows), 'insurable' => (string) $insurableTotal->toScale(2), 'own' => $this->ownAfp($runId)];
        if (DB::table('payroll_periods')->where('id', $f['payroll_period_id'])->whereNotIn('status', ['closed', 'paid', 'accounted'])->exists()) {
            $out['draft_only'] = true;
            $out['draft_reasons'][] = 'period_not_closed';
        }

        return ['planilla' => $rows];
    }

    private function exceptionReason(string $emp, string $from, string $to): string
    {
        $types = DB::table('absence_requests AS a')->join('absence_type_rules AS t', fn ($j) => $j->on('t.absence_type_id', '=', 'a.absence_type_id')->where('t.valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('t.valid_to')->orWhere('t.valid_to', '>=', $to)))->where('a.employment_id', $emp)
            ->whereNotNull('a.starts_on')->where('a.starts_on', '<=', $to)->where('a.ends_on', '>=', $from)
            ->whereIn('a.status', ['approved', 'in_progress', 'completed', 'pending_document'])->get(['t.is_paid', 't.subsidy_kind']);
        foreach ($types as $t) {
            if ((string) $t->subsidy_kind !== 'none') {
                return 'subsidy';
            }
        }
        foreach ($types as $t) {
            if (! (bool) $t->is_paid) {
                return 'unpaid_leave';
            }
        }

        return 'other';
    }

    /** Suma de las líneas de la relación cuyos conceptos afectan la base indicada (CPT-3). */
    private function baseAmount(string $runId, string $emp, string $base): BigDecimal
    {
        $sum = DB::table('payroll_run_lines AS l')->join('payroll_run_employments AS re', 're.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_concept_affectations AS a', 'a.payroll_concept_version_id', '=', 'l.payroll_concept_version_id')
            ->where('l.payroll_run_id', $runId)->where('re.employment_id', $emp)->where('a.base_code', $base)->where('a.affects', true)->sum('l.amount');

        return BigDecimal::of((string) ($sum ?: '0'));
    }

    /**
     * Aporte, comisión y prima calculados por la planilla (para conciliar con la liquidación de AFPnet).
     *
     * @return array<string, string>
     */
    public function ownAfp(string $runId): array
    {
        $codes = (array) ($this->sources->pack()['conciliacion_afpnet'] ?? []);
        $out = [];
        foreach ($codes as $key => $code) {
            $sum = DB::table('payroll_run_lines AS l')->join('payroll_concepts AS c', 'c.id', '=', 'l.payroll_concept_id')
                ->where('l.payroll_run_id', $runId)->whereRaw('lower(c.code) = ?', [strtolower((string) $code)])->sum('l.amount');
            $out[(string) $key] = (string) BigDecimal::of((string) ($sum ?: '0'))->toScale(2);
        }

        return $out;
    }

    /**
     * @param  array{legal_entity_id: string, kind: string, period_code: string, payroll_period_id: string|null}  $f
     * @param  array{files: list<array<string, mixed>>, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, draft_only: bool, draft_reasons: list<string>, totals: array<string, mixed>, events?: list<string>}  $out
     * @return array<int|string, list<array<string, mixed>>>
     */
    private function tregistroRows(array $f, string $to, array &$out): array
    {
        $events = DB::table('registry_events')->where('legal_entity_id', $f['legal_entity_id'])->whereIn('status', ['pending', 'overdue'])
            ->whereIn('kind', ['worker_registration', 'worker_termination', 'worker_update'])->whereNotNull('employment_id')
            ->orderBy('event_date')->orderBy('id')->get(['id', 'employment_id', 'kind', 'event_date']);
        $ide = [];
        $tra = [];
        $per = [];
        $ids = [];
        foreach ($events as $ev) {
            $emp = (string) $ev->employment_id;
            $w = $this->sources->worker($emp);
            if ($w === []) {
                continue;
            }
            $ids[] = (string) $ev->id;
            $on = (string) $ev->kind === 'worker_registration' ? (string) $w['_hire_date'] : substr((string) $ev->event_date, 0, 10);
            if ((string) $ev->kind !== 'worker_termination') {
                $profile = $this->sources->profile($emp, $on);
                // RN-DEC-05 · Los datos que faltan se listan antes de generar el alta.
                if ($profile === null) {
                    $out['errors'][] = ['ref' => $emp, 'field' => 'registry_profile', 'rule' => 'missing', 'event_id' => (string) $ev->id];
                } else {
                    foreach ($profile['missing'] as $m) {
                        $out['errors'][] = ['ref' => $emp, 'field' => $m, 'rule' => 'required', 'event_id' => (string) $ev->id];
                    }
                    $pension = $this->sources->pension($emp, $on);
                    $tra[] = array_merge($w, $profile['values'], ['pension.cuspp' => $pension['cuspp'] ?? null]);
                }
                if ((string) $ev->kind === 'worker_registration') {
                    $ide[] = $w;
                }
            }
            $per[] = array_merge($w, [
                'period.category_code' => '1', 'period.record_type_code' => '1', 'period.starts_on' => $w['_hire_date'],
                'period.ends_on' => (string) $ev->kind === 'worker_termination' ? $w['_termination_date'] : null,
                'period.indicator_code' => null, 'period.eps_code' => null,
            ]);
        }
        if ($ids === []) {
            $out['warnings'][] = ['ref' => null, 'code' => 'no_pending_events'];
        }
        $out['events'] = $ids;
        $out['totals'] = ['events' => count($ids)];

        return ['4' => $ide, '5' => $tra, '11' => $per];
    }
}
