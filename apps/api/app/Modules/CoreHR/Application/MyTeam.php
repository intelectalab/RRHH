<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Permissions\TeamLine;
use App\Shared\Time\ApprovedAbsences;
use App\Shared\Time\VacationBalances;
use Illuminate\Support\Facades\DB;

/**
 * MSS-2 · «Mi equipo»: las personas que reportan al usuario según la línea de reporte vigente (RN-MSS-01),
 * directas y, con people.team.view_indirect, también las indirectas. Cada fila trae su nivel, su estado de
 * hoy (ausente o de vacaciones, sin el motivo) y las acciones que el usuario puede iniciar.
 *
 * El sueldo viaja solo con el permiso de campo (regla 6) y su lectura queda registrada (regla 7).
 */
final class MyTeam
{
    public const SCHEMA = 'Employment';

    public function __construct(
        private readonly TeamLine $team,
        private readonly EmploymentPresenter $presenter,
        private readonly SensitiveFields $sensitive,
        private readonly ModuleGate $modules,
        private readonly ApprovedAbsences $absences,
        private readonly VacationBalances $vacations,
    ) {}

    /**
     * @param  array{level?: string|null, q?: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(string $userId, EffectivePermissions $permissions, string $today, array $filters = []): array
    {
        $indirect = $permissions->has('people.team.view_indirect');
        $members = $this->team->members($userId, $today, $indirect);
        $directCount = count(array_filter($members, fn (int $d) => $d === 1));

        $level = $filters['level'] ?? null;
        if ($level === 'direct') {
            $members = array_filter($members, fn (int $d) => $d === 1);
        } elseif ($level === 'indirect') {
            $members = array_filter($members, fn (int $d) => $d > 1);
        }

        $query = Employment::query()->join('people as p', 'p.id', '=', 'employments.person_id')
            ->whereIn('employments.id', array_keys($members) === [] ? ['00000000-0000-0000-0000-000000000000'] : array_keys($members))
            ->whereNull('p.anonymized_at')
            ->select('employments.*')->orderBy('p.paternal_surname')->orderBy('p.first_names')->orderBy('employments.id');
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($w) use ($like): void {
                $w->whereRaw("concat_ws(' ', p.first_names, p.preferred_name, p.paternal_surname, p.maternal_surname) ilike ?", [$like])
                    ->orWhere('employments.employee_code', 'ilike', $like);
            });
        }
        /** @var array<int, Employment> $employments */
        $employments = $query->get()->all();

        $withSalary = $permissions->has('people.employments.salary.view');
        $rows = $this->sensitive->presentMany(
            self::SCHEMA,
            $this->presenter->presentMany($employments, $withSalary),
            $permissions,
            'api',
            array_map(fn (Employment $e) => $e->person_id, $employments),
        );

        $ids = array_values(array_map(fn (Employment $e) => (string) $e->id, $employments));
        $onVacation = $this->canSee($permissions, 'time.vacation_requests.view') ? array_flip($this->vacations->onLeave($ids, $today)) : [];
        $seeAbsences = $this->canSee($permissions, 'time.absences.view');
        $canPropose = $permissions->has('people.assignments.propose') || $permissions->has('people.assignments.create');
        // Solicitar a nombre de un subordinado exige un alcance mayor que «propio».
        $canRequestVacation = $this->canSee($permissions, 'time.vacation_requests.create') && $permissions->scopeFor('time.vacation_requests.create') !== 'own';

        $out = [];
        $counts = ['present' => 0, 'absent' => 0, 'on_vacation' => 0];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $today_status = 'present';
            if (isset($onVacation[$id])) {
                $today_status = 'on_vacation';
            } elseif ($seeAbsences && $this->absences->on($id, $today) !== []) {
                // Solo que está ausente: el tipo y el motivo no viajan (salud, RN-MSS-03).
                $today_status = 'absent';
            }
            $counts[$today_status]++;
            $actions = [];
            if ($canPropose) {
                $actions[] = 'propose_movement';
            }
            if ($canRequestVacation) {
                $actions[] = 'request_vacation';
            }
            $out[] = $row + [
                'level' => $members[$id] ?? 1,
                'is_direct' => ($members[$id] ?? 1) === 1,
                'today_status' => $today_status,
                'team_actions' => $actions,
            ];
        }

        return [
            'data' => $out,
            'meta' => [
                'total' => count($out),
                'direct' => $directCount,
                'includes_indirect' => $indirect,
                'today' => $counts,
                'vacant_positions' => $this->vacancies($userId, $permissions, $today),
            ],
        ];
    }

    private function canSee(EffectivePermissions $permissions, string $permission): bool
    {
        $module = $this->modules->moduleOf($permission);

        return $module !== null && $this->modules->isEnabled($module) && $permissions->has($permission);
    }

    /**
     * Posiciones vacantes que dependen de las del usuario (POS-2): aprobadas, abiertas o en reclutamiento.
     *
     * @return list<array<string, mixed>>
     */
    private function vacancies(string $userId, EffectivePermissions $permissions, string $today): array
    {
        if (! $this->canSee($permissions, 'org.positions.view')) {
            return [];
        }
        $ids = $this->team->positionsUnder($userId, $today);
        if ($ids === []) {
            return [];
        }

        return array_values(DB::table('positions as p')->leftJoin('org_units as u', 'u.id', '=', 'p.org_unit_id')
            ->whereIn('p.id', $ids)->whereNull('p.deleted_at')->whereIn('p.status', ['approved', 'open', 'recruiting'])
            ->orderBy('p.title')->limit(50)
            ->get(['p.id', 'p.code', 'p.title', 'p.status', 'u.name as org_unit_name'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'code' => (string) $r->code, 'title' => (string) $r->title, 'status' => (string) $r->status, 'org_unit_name' => $r->org_unit_name === null ? null : (string) $r->org_unit_name])
            ->values()->all());
    }
}
