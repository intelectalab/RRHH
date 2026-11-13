<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Domain\EmploymentStateMachine;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Security\FieldCipher;
use Illuminate\Support\Facades\DB;

/**
 * COL-9 · Lectura de relaciones laborales con su situación vigente (RN-COL-05): la asignación, el régimen y
 * el contrato se leen de sus tablas con vigencia, nunca de una copia. La «situación vigente» de un ingreso
 * futuro es la de su fecha de ingreso.
 */
final class EmploymentPresenter
{
    public function __construct(private readonly EmploymentStateMachine $machine) {}

    /**
     * SQL de la asignación vigente por relación: la última que empezó hasta hoy, o hasta el ingreso si aún
     * no empezó (para una relación cesada, la última que tuvo).
     */
    public const CURRENT_ASSIGNMENT_SQL = 'SELECT DISTINCT ON (a.employment_id) a.* FROM employment_assignments a '
        .'JOIN employments ce ON ce.id = a.employment_id '
        .'WHERE a.valid_from <= GREATEST(current_date, ce.hire_date) ORDER BY a.employment_id, a.valid_from DESC';

    /**
     * @param  array<int, Employment>  $employments
     * @param  bool  $withSalary  incluye la compensación vigente (el permiso de campo la quita después)
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $employments, bool $withSalary = false): array
    {
        if ($employments === []) {
            return [];
        }
        $ids = array_map(fn (Employment $e) => $e->id, $employments);
        $people = DB::table('people')->whereIn('id', array_map(fn (Employment $e) => $e->person_id, $employments))->get()->keyBy('id');
        $docs = DB::table('person_documents')->whereIn('person_id', $people->keys()->all())->whereNull('deleted_at')
            ->orderByDesc('is_primary')->orderBy('created_at')->get()->groupBy('person_id');
        $entities = DB::table('legal_entities')->whereIn('id', array_map(fn (Employment $e) => $e->legal_entity_id, $employments))->pluck('legal_name', 'id');

        $assignments = [];
        foreach (DB::table(DB::raw('('.self::CURRENT_ASSIGNMENT_SQL.') as ca'))->whereIn('ca.employment_id', $ids)->get() as $row) {
            $assignments[(string) $row->employment_id] = $row;
        }
        $names = fn (string $table, string $col, array $ids) => $ids === [] ? collect() : DB::table($table)->whereIn('id', $ids)->pluck($col, 'id');
        $col = fn (string $field) => array_values(array_unique(array_filter(array_map(fn ($a) => $a->{$field}, $assignments))));
        $profiles = $names('job_profiles', 'name', $col('job_profile_id'));
        $positions = $names('positions', 'title', $col('position_id'));
        $units = $names('org_units', 'name', $col('org_unit_id'));
        $sites = $names('sites', 'name', $col('site_id'));
        $centers = $names('cost_centers', 'name', $col('cost_center_id'));
        // Jefe efectivo: el indicado en la asignación o, si no hay, quien ocupa hoy la posición jefe (POS-1).
        $bossPositions = $col('position_id') === [] ? collect() : DB::table('positions')->whereIn('id', $col('position_id'))
            ->whereNotNull('reports_to_position_id')->pluck('reports_to_position_id', 'id');
        $bossOccupants = $bossPositions->isEmpty() ? collect() : DB::table(DB::raw('('.self::CURRENT_ASSIGNMENT_SQL.') as bo'))
            ->join('employments as be', 'be.id', '=', 'bo.employment_id')
            ->whereIn('bo.position_id', $bossPositions->values()->unique()->all())
            ->whereIn('be.status', ['active', 'suspended', 'terminating'])
            ->orderBy('be.hire_date')->get(['bo.position_id', 'bo.employment_id'])->unique('position_id')->pluck('employment_id', 'position_id');
        $managerOf = [];
        foreach ($assignments as $employmentId => $a) {
            $boss = $a->manager_employment_id ?? (($a->position_id !== null && isset($bossPositions[$a->position_id])) ? ($bossOccupants[$bossPositions[$a->position_id]] ?? null) : null);
            if ($boss !== null && $boss !== $employmentId) {
                $managerOf[$employmentId] = (string) $boss;
            }
        }
        $managerIds = array_values(array_unique($managerOf));
        $managers = $managerIds === [] ? collect() : DB::table('employments as m')->join('people as p', 'p.id', '=', 'm.person_id')
            ->whereIn('m.id', $managerIds)->get(['m.id', 'p.first_names', 'p.preferred_name', 'p.paternal_surname', 'p.maternal_surname'])->keyBy('id');

        $at = fn (string $table) => DB::table($table.' as t')->join('employments as te', 'te.id', '=', 't.employment_id')
            ->whereIn('t.employment_id', $ids)->whereRaw('t.valid_from <= GREATEST(current_date, te.hire_date)')
            ->orderByDesc('t.valid_from');
        $regimes = $at('employment_labor_regimes')->get(['t.employment_id', 't.labor_regime_code'])->unique('employment_id')->keyBy('employment_id');
        $contracts = DB::table('contracts')->whereIn('employment_id', $ids)->whereNull('deleted_at')->where('status', '<>', 'cancelled')
            ->orderByDesc('valid_from')->get(['employment_id', 'contract_type_id', 'valid_to'])->unique('employment_id')->keyBy('employment_id');
        $salaries = $withSalary ? $at('employment_compensations')->get(['t.employment_id', 't.base_salary', 't.currency'])->unique('employment_id')->keyBy('employment_id') : collect();

        return array_map(function (Employment $e) use ($people, $docs, $entities, $assignments, $profiles, $positions, $units, $sites, $centers, $managers, $managerOf, $regimes, $contracts, $salaries, $withSalary): array {
            $p = $people->get($e->person_id);
            $doc = $docs->get($e->person_id)?->first();
            $a = $assignments[$e->id] ?? null;
            $manager = isset($managerOf[$e->id]) ? $managers->get($managerOf[$e->id]) : null;
            $contract = $contracts->get($e->id);
            $salary = $salaries->get($e->id);
            $number = $doc === null ? null : FieldCipher::decrypt((string) $doc->number_enc);

            $row = [
                'id' => $e->id,
                'person_id' => $e->person_id,
                'person_name' => $p === null ? null : self::name($p),
                'document_number_masked' => $number === null ? null : IdentityDocuments::mask($number),
                'legal_entity_id' => $e->legal_entity_id,
                'legal_entity_name' => $entities[$e->legal_entity_id] ?? null,
                'employee_code' => $e->employee_code,
                'kind' => $e->kind,
                'worker_category' => $e->worker_category,
                'hire_date' => $e->hire_date->toDateString(),
                'seniority_date' => $e->seniority_date?->toDateString(),
                'probation_ends_on' => $e->probation_ends_on?->toDateString(),
                'termination_date' => $e->termination_date?->toDateString(),
                'status' => $e->status,
                'rehire_of_employment_id' => $e->rehire_of_employment_id,
                'work_email' => $e->work_email,
                'work_phone' => $e->work_phone,
                'work_phone_ext' => $e->work_phone_ext,
                'job_profile_id' => $a?->job_profile_id,
                'job_profile_name' => $a === null ? null : ($profiles[$a->job_profile_id] ?? null),
                'position_id' => $a?->position_id,
                'position_title' => $a?->position_id === null ? null : ($positions[$a->position_id] ?? null),
                'org_unit_id' => $a?->org_unit_id,
                'org_unit_name' => $a === null ? null : ($units[$a->org_unit_id] ?? null),
                'site_id' => $a?->site_id,
                'site_name' => $a === null ? null : ($sites[$a->site_id] ?? null),
                'cost_center_id' => $a?->cost_center_id,
                'cost_center_name' => $a?->cost_center_id === null ? null : ($centers[$a->cost_center_id] ?? null),
                'manager_employment_id' => $a?->manager_employment_id,
                'manager_name' => $manager === null ? null : self::name($manager),
                'work_modality' => $a?->work_modality,
                'labor_regime_code' => $regimes->get($e->id)?->labor_regime_code,
                'contract_type_id' => $contract?->contract_type_id,
                'contract_end_date' => $contract?->valid_to === null ? null : (string) $contract->valid_to,
                'allowed_actions' => $this->machine->manualActions($e->status),
                'version' => $e->lock_version,
                'created_at' => $e->created_at->toIso8601String(),
                'updated_at' => $e->updated_at->toIso8601String(),
            ];
            if ($withSalary) {
                $row['base_salary'] = $salary === null ? null : ['amount' => (string) $salary->base_salary, 'currency' => (string) $salary->currency];
            }

            return $row;
        }, $employments);
    }

    /** Nombre visible de una fila de people (nombre preferido si lo tiene). */
    public static function name(object $p): string
    {
        $first = isset($p->preferred_name) && is_string($p->preferred_name) && $p->preferred_name !== '' ? $p->preferred_name : (string) ($p->first_names ?? '');

        return trim(implode(' ', array_filter([$first, (string) ($p->paternal_surname ?? ''), isset($p->maternal_surname) ? (string) $p->maternal_surname : ''])));
    }
}
