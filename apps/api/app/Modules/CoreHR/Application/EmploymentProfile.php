<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * COL-3 · COL-5 · Cabecera de la ficha 360 y datos laborales vigentes.
 *
 * - La antigüedad la calcula el servidor (regla 11): desde la fecha de antigüedad, o el ingreso, hasta hoy o
 *   hasta el cese. Un ingreso futuro no tiene antigüedad aún.
 * - Cada dato laboral dice desde cuándo rige (la fila vigente de su tabla con vigencia) y a qué historia
 *   enlaza; ninguno se edita en sitio (COL-5): la asignación cambia con un movimiento con fecha efectiva.
 */
final class EmploymentProfile
{
    public function __construct(private readonly EmploymentPresenter $presenter) {}

    /**
     * @param  array<int, string>|null  $visibleEmployments  relaciones de la persona dentro del alcance (null = todas)
     * @return array<string, mixed>
     */
    public function header(Employment $employment, ?array $visibleEmployments, ?string $viewerPersonId): array
    {
        $row = $this->presenter->presentMany([$employment])[0];
        $person = DB::table('people')->where('id', $employment->person_id)->first();
        $relations = DB::table('employments as e')->join('legal_entities as le', 'le.id', '=', 'e.legal_entity_id')
            ->where('e.person_id', $employment->person_id)->whereNull('e.deleted_at')
            ->when($visibleEmployments !== null, fn ($q) => $q->whereIn('e.id', $visibleEmployments ?? []))
            ->orderByDesc('e.hire_date')
            ->get(['e.id', 'le.legal_name', 'e.employee_code', 'e.status', 'e.hire_date', 'e.termination_date']);

        return [
            'id' => $employment->id,
            'person_id' => $employment->person_id,
            'person_name' => $row['person_name'],
            'first_names' => $person?->first_names,
            'has_photo' => $person !== null && $person->photo_file_id !== null,
            'employee_code' => $employment->employee_code,
            'legal_entity_id' => $employment->legal_entity_id,
            'legal_entity_name' => $row['legal_entity_name'],
            'job_profile_name' => $row['job_profile_name'],
            'position_title' => $row['position_title'],
            'org_unit_name' => $row['org_unit_name'],
            'manager_employment_id' => $row['manager_employment_id'],
            'manager_name' => $row['manager_name'],
            'status' => $employment->status,
            'hire_date' => $employment->hire_date->toDateString(),
            'termination_date' => $employment->termination_date?->toDateString(),
            'seniority' => $this->seniority($employment),
            'is_self' => $viewerPersonId !== null && $viewerPersonId === $employment->person_id,
            'relations' => $relations->map(fn ($r) => [
                'id' => (string) $r->id, 'legal_entity_name' => (string) $r->legal_name, 'employee_code' => (string) $r->employee_code,
                'status' => (string) $r->status, 'hire_date' => (string) $r->hire_date,
                'termination_date' => $r->termination_date === null ? null : (string) $r->termination_date,
            ])->all(),
            'allowed_actions' => $row['allowed_actions'],
            'version' => $employment->lock_version,
        ];
    }

    /**
     * Antigüedad calculada (nunca digitada): años, meses y días cumplidos y total de días.
     *
     * @return array{from: string, to: string, years: int, months: int, days: int, total_days: int}|null
     */
    public function seniority(Employment $employment, ?string $asOf = null): ?array
    {
        $from = CarbonImmutable::parse(($employment->seniority_date ?? $employment->hire_date)->toDateString());
        $today = CarbonImmutable::parse($asOf ?? now()->toDateString());
        $to = $employment->termination_date !== null && $employment->termination_date->toDateString() < $today->toDateString()
            ? CarbonImmutable::parse($employment->termination_date->toDateString()) : $today;
        if ($to->lessThan($from) || $employment->status === 'cancelled') {
            return null;
        }
        $diff = $from->diff($to);

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'years' => (int) $diff->y, 'months' => (int) $diff->m, 'days' => (int) $diff->d,
            'total_days' => (int) $from->diffInDays($to),
        ];
    }

    /**
     * COL-5 · Datos laborales vigentes a la fecha: cada uno con su valor, desde cuándo rige y su historia.
     *
     * @return array<string, mixed>
     */
    public function workData(Employment $employment, ?string $asOf = null): array
    {
        $day = $asOf ?? max(now()->toDateString(), $employment->hire_date->toDateString());
        $assignmentQuery = DB::table('employment_assignments')->where('employment_id', $employment->id);
        EffectiveDated::applyAsOf($assignmentQuery, $day);
        $a = $assignmentQuery->orderByDesc('valid_from')->first();
        // Una relación cesada muestra su última asignación.
        $a ??= DB::table('employment_assignments')->where('employment_id', $employment->id)->orderByDesc('valid_from')->first();

        $name = fn (string $table, string $col, ?string $id) => $id === null ? null : DB::table($table)->where('id', $id)->value($col);
        $regimeQuery = DB::table('employment_labor_regimes')->where('employment_id', $employment->id);
        EffectiveDated::applyAsOf($regimeQuery, $day);
        $regime = $regimeQuery->orderByDesc('valid_from')->first();
        $contract = DB::table('contracts')->where('employment_id', $employment->id)->whereNull('deleted_at')->where('status', '<>', 'cancelled')
            ->where('valid_from', '<=', $day)->orderByDesc('valid_from')->first();
        $statusSince = DB::table('employment_status_events')->where('employment_id', $employment->id)->where('to_status', $employment->status)
            ->orderByDesc('effective_on')->value('effective_on');
        $manager = $a?->manager_employment_id === null ? null : DB::table('employments as m')->join('people as p', 'p.id', '=', 'm.person_id')
            ->where('m.id', $a->manager_employment_id)->first(['p.first_names', 'p.preferred_name', 'p.paternal_surname', 'p.maternal_surname']);
        $since = $a?->valid_from === null ? null : (string) $a->valid_from;
        $hire = $employment->hire_date->toDateString();

        $item = fn (mixed $value, ?string $label, ?string $from, ?string $history) => ['value' => $value, 'label' => $label, 'since' => $from, 'history' => $history];

        return [
            'employment_id' => $employment->id,
            'as_of' => $day,
            'fields' => [
                'employee_code' => $item($employment->employee_code, null, $hire, null),
                'legal_entity' => $item($employment->legal_entity_id, $name('legal_entities', 'legal_name', $employment->legal_entity_id), $hire, null),
                'site' => $item($a?->site_id, $name('sites', 'name', $a?->site_id), $since, 'assignments'),
                'org_unit' => $item($a?->org_unit_id, $name('org_units', 'name', $a?->org_unit_id), $since, 'assignments'),
                'job_profile' => $item($a?->job_profile_id, $name('job_profiles', 'name', $a?->job_profile_id), $since, 'assignments'),
                'position' => $item($a?->position_id, $name('positions', 'title', $a?->position_id), $since, 'assignments'),
                'manager' => $item($a?->manager_employment_id, $manager === null ? null : EmploymentPresenter::name($manager), $since, 'assignments'),
                'cost_center' => $item($a?->cost_center_id, $name('cost_centers', 'name', $a?->cost_center_id), $since, 'assignments'),
                'work_modality' => $item($a?->work_modality, null, $since, 'assignments'),
                'hire_date' => $item($hire, null, null, null),
                'seniority_date' => $item($employment->seniority_date?->toDateString(), null, null, null),
                'kind' => $item($employment->kind, null, $hire, null),
                'worker_category' => $item($employment->worker_category, null, $hire, null),
                'labor_regime' => $item($regime?->labor_regime_code, $regime === null ? null : DB::table('labor_regimes')->where('code', $regime->labor_regime_code)->value('name'),
                    $regime?->valid_from === null ? null : (string) $regime->valid_from, null),
                'contract_type' => $item($contract?->contract_type_id, $name('contract_types', 'name', $contract?->contract_type_id),
                    $contract?->valid_from === null ? null : (string) $contract->valid_from, null),
                // El horario lo asigna el módulo Tiempo (M-ASI); hasta entonces no hay dato que mostrar.
                'schedule' => $item(null, null, null, null),
                'status' => $item($employment->status, null, $statusSince === null ? null : (string) $statusSince, 'status'),
            ],
            'seniority' => $this->seniority($employment, $asOf),
        ];
    }
}
