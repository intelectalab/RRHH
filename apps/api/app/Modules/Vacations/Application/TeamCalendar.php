<?php

namespace App\Modules\Vacations\Application;

use App\Modules\Vacations\Domain\VacationRequestStateMachine;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Settings\ParameterResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * VAC-4 · Calendario de ausencias del equipo: vacaciones y ausencias, aprobadas y en trámite, por persona
 * y día, con el alcance del usuario aplicado en la consulta (regla 6).
 *
 * - Modo «equipo» (jefe, RRHH): las personas de su alcance con el tipo y el estado de cada ausencia. Un
 *   tipo de ausencia confidencial se muestra como «Ausencia justificada» sin el permiso de certificados
 *   médicos (RN-AUS-05).
 * - Modo «compañeros» (alcance propio): las personas de su misma unidad, solo lo aprobado y sin tipo ni
 *   motivo («Ausente»), para coordinar sus fechas sin ver datos de otros.
 * - Cobertura: si en una unidad estarán ausentes el mismo día más personas que el máximo de la
 *   organización o empresa (vacaciones.maximo_ausentes_simultaneos; 0 = sin límite), se alerta. Cuenta
 *   personas, no nombres, así que alcanza también a quien no ve a toda la unidad.
 */
final class TeamCalendar
{
    public const PERMISSION = 'time.vacation_requests.view';

    public const MAX_RANGE_DAYS = 92;

    private const ABSENCE_ACTIVE = ['pending', 'pending_document', 'approved', 'in_progress', 'completed'];

    private const ABSENCE_APPROVED = ['approved', 'in_progress', 'completed'];

    private const ABSENCE_PENDING = ['pending', 'pending_document'];

    public function __construct(
        private readonly DataScope $scope,
        private readonly ParameterResolver $parameters,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, ?EffectivePermissions $permissions, string $from, string $to, ?string $orgUnitId): array
    {
        if ($to < $from) {
            throw new AyniException('validation_failed', 422, (string) trans('vacations.team.invalid_range'), [['field' => 'to', 'rule' => 'after_or_equal', 'message' => (string) trans('vacations.team.invalid_range')]]);
        }
        if ((int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_RANGE_DAYS) {
            throw new AyniException('validation_failed', 422, (string) trans('vacations.team.range_too_long', ['days' => self::MAX_RANGE_DAYS]), [['field' => 'to', 'rule' => 'max_range', 'message' => (string) trans('vacations.team.range_too_long', ['days' => self::MAX_RANGE_DAYS])]]);
        }
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AyniException('unauthenticated', 401);
        }
        $userId = (string) $user->getAuthIdentifier();
        $peers = ($permissions?->scopeFor(self::PERMISSION) ?? 'own') === 'own';
        $viewerPerson = DB::table('users')->where('id', $userId)->value('person_id');

        // Personas: asignación vigente al inicio del rango, relación activa.
        $people = DB::table('employments as e')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->join('employment_assignments as a', function ($j) use ($from): void {
                $j->on('a.employment_id', '=', 'e.id')->where('a.valid_from', '<=', $from)
                    ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $from));
            })
            ->leftJoin('org_units as u', 'u.id', '=', 'a.org_unit_id')
            ->whereNull('e.deleted_at')->whereIn('e.status', ['active', 'suspended', 'terminating']);
        if ($peers) {
            $units = $this->unitsOf($viewerPerson === null ? null : (string) $viewerPerson, $from);
            $people->whereIn('a.org_unit_id', $units === [] ? ['00000000-0000-0000-0000-000000000000'] : $units);
        } else {
            $this->scope->applyEmployment($people, $request, self::PERMISSION, 'e.id');
        }
        if ($orgUnitId !== null) {
            $people->where('a.org_unit_id', $orgUnitId);
        }
        $rows = $people->orderBy('u.name')->orderBy('p.paternal_surname')->orderBy('p.first_names')
            ->get(['e.id as employment_id', 'e.employee_code', 'e.person_id', 'e.legal_entity_id', 'p.first_names', 'p.paternal_surname', 'a.org_unit_id', 'u.name as org_unit_name']);
        $ids = $rows->pluck('employment_id')->map(fn ($id) => (string) $id)->values()->all();

        $items = $this->items($ids, $from, $to, $peers, $permissions, $viewerPerson === null ? null : (string) $viewerPerson);

        // Conteo por día de lo visible (sin cálculo en el navegador: el servidor entrega los totales).
        $days = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $days[$d->toDateString()] = [];
        }
        foreach ($items as $employmentId => $list) {
            foreach ($list as $item) {
                foreach (array_keys($days) as $date) {
                    if ($date >= $item['starts_on'] && $date <= $item['ends_on']) {
                        $days[$date][$employmentId] = true;
                    }
                }
            }
        }

        $alerts = [];
        if (! $peers) {
            $units = [];
            foreach ($rows as $r) {
                if ($r->org_unit_id !== null) {
                    $units[(string) $r->org_unit_id] = ['name' => $r->org_unit_name, 'legal_entity_id' => (string) $r->legal_entity_id];
                }
            }
            foreach ($units as $unitId => $unit) {
                $limit = $this->limit($unit['legal_entity_id']);
                if ($limit <= 0) {
                    continue;
                }
                foreach ($this->ranges($this->counts($unitId, $from, $to, null), $limit) as $range) {
                    $alerts[] = ['org_unit_id' => $unitId, 'org_unit_name' => $unit['name']] + $range;
                }
            }
        }
        $overDays = [];
        foreach ($alerts as $a) {
            for ($d = CarbonImmutable::parse($a['from']); $d->toDateString() <= $a['to']; $d = $d->addDay()) {
                $overDays[$d->toDateString()] = true;
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'mode' => $peers ? 'peers' : 'team',
            'people' => $rows->map(fn (object $r): array => [
                'employment_id' => (string) $r->employment_id,
                'person_id' => (string) $r->person_id,
                'person_name' => trim($r->first_names.' '.$r->paternal_surname),
                'employee_code' => $peers ? null : $r->employee_code,
                'org_unit_id' => $r->org_unit_id === null ? null : (string) $r->org_unit_id,
                'org_unit_name' => $r->org_unit_name,
                'is_self' => $viewerPerson !== null && (string) $r->person_id === (string) $viewerPerson,
                'items' => $items[(string) $r->employment_id] ?? [],
            ])->values()->all(),
            'days' => array_map(fn (string $date): array => [
                'date' => $date, 'absent' => count($days[$date]), 'coverage_exceeded' => isset($overDays[$date]),
            ], array_keys($days)),
            'alerts' => $alerts,
        ];
    }

    /**
     * Cobertura de una solicitud: días en que la unidad del trabajador superaría el máximo de ausentes
     * contándolo a él. Lista vacía si no hay límite o no se supera.
     *
     * @return list<array{from: string, to: string, count: int, limit: int, org_unit_id: string}>
     */
    public function coverageFor(string $employmentId, string $from, string $to, ?string $ignoreRequestId = null): array
    {
        $row = DB::table('employments as e')->leftJoin('employment_assignments as a', function ($j) use ($from): void {
            $j->on('a.employment_id', '=', 'e.id')->where('a.valid_from', '<=', $from)
                ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $from));
        })->where('e.id', $employmentId)->first(['e.legal_entity_id', 'a.org_unit_id']);
        if ($row === null || $row->org_unit_id === null) {
            return [];
        }
        $limit = $this->limit((string) $row->legal_entity_id);
        if ($limit <= 0) {
            return [];
        }
        $counts = $this->counts((string) $row->org_unit_id, $from, $to, $employmentId);
        foreach ($counts as $date => $n) {
            $counts[$date] = $n + 1;
        }

        return array_map(fn (array $r): array => $r + ['org_unit_id' => (string) $row->org_unit_id], $this->ranges($counts, $limit));
    }

    // ------------------------------------------------------------------ Internos

    /**
     * @param  array<int, string>  $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function items(array $ids, string $from, string $to, bool $peers, ?EffectivePermissions $permissions, ?string $viewerPerson): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $label = (string) trans('vacations.team.absent');

        $vacations = DB::table('vacation_requests as v')->join('employments as e', 'e.id', '=', 'v.employment_id')
            ->whereIn('v.employment_id', $ids)->whereNull('v.deleted_at')->whereIn('v.kind', ['leave', 'advance'])
            ->whereIn('v.status', VacationRequestStateMachine::ACTIVE_STATES)
            ->where('v.starts_on', '<=', $to)->where('v.ends_on', '>=', $from)->orderBy('v.starts_on')
            ->get(['v.id', 'v.employment_id', 'v.starts_on', 'v.ends_on', 'v.status', 'v.days', 'e.person_id']);
        foreach ($vacations as $v) {
            $self = $viewerPerson !== null && (string) $v->person_id === $viewerPerson;
            $detail = ! $peers || $self;
            // A los compañeros solo se les muestra lo aprobado; lo propio, también en trámite.
            if (! $detail && ! in_array($v->status, VacationRequestStateMachine::APPROVED_STATES, true)) {
                continue;
            }
            $out[(string) $v->employment_id][] = [
                'id' => $detail ? (string) $v->id : null,
                'source' => $detail ? 'vacation' : 'other',
                'label' => $detail ? (string) trans('vacations.team.vacation') : $label,
                'status' => $detail ? (string) $v->status : 'approved',
                'pending' => in_array($v->status, VacationRequestStateMachine::PENDING_STATES, true),
                'starts_on' => substr((string) $v->starts_on, 0, 10),
                'ends_on' => substr((string) $v->ends_on, 0, 10),
                'link' => $detail ? '/tiempo/vacaciones?id='.$v->id : null,
            ];
        }

        $medical = $permissions?->has('time.absences.medical_certificate.view') ?? false;
        $absences = DB::table('absence_requests as r')->join('absence_types as t', 't.id', '=', 'r.absence_type_id')
            ->join('employments as e', 'e.id', '=', 'r.employment_id')
            ->whereIn('r.employment_id', $ids)->whereNull('r.deleted_at')
            ->whereIn('r.status', self::ABSENCE_ACTIVE)
            ->where('r.starts_on', '<=', $to)->where('r.ends_on', '>=', $from)->orderBy('r.starts_on')
            ->get(['r.id', 'r.employment_id', 'r.starts_on', 'r.ends_on', 'r.status', 't.name as type_name', 't.is_confidential', 'e.person_id']);
        foreach ($absences as $r) {
            $self = $viewerPerson !== null && (string) $r->person_id === $viewerPerson;
            $detail = ! $peers || $self;
            if (! $detail && ! in_array($r->status, self::ABSENCE_APPROVED, true)) {
                continue;
            }
            $confidential = (bool) $r->is_confidential && ! $medical && ! $self;
            $out[(string) $r->employment_id][] = [
                'id' => $detail ? (string) $r->id : null,
                'source' => $detail ? 'absence' : 'other',
                'label' => ! $detail ? $label : ($confidential ? (string) trans('absences.requests.justified') : (string) $r->type_name),
                'status' => $detail ? (string) $r->status : 'approved',
                'pending' => in_array($r->status, self::ABSENCE_PENDING, true),
                'starts_on' => substr((string) $r->starts_on, 0, 10),
                'ends_on' => substr((string) $r->ends_on, 0, 10),
                'link' => $detail ? '/tiempo/ausencias?id='.$r->id : null,
            ];
        }
        foreach ($out as $k => $list) {
            usort($list, fn (array $a, array $b): int => strcmp($a['starts_on'], $b['starts_on']));
            $out[$k] = $list;
        }

        return $out;
    }

    /**
     * Personas distintas ausentes por día en una unidad (vacaciones y ausencias, aprobadas o en trámite).
     *
     * @return array<string, int>
     */
    private function counts(string $orgUnitId, string $from, string $to, ?string $excludeEmploymentId): array
    {
        $members = DB::table('employment_assignments as a')->join('employments as e', 'e.id', '=', 'a.employment_id')
            ->where('a.org_unit_id', $orgUnitId)->where('a.valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $from))
            ->whereNull('e.deleted_at')->whereIn('e.status', ['active', 'suspended', 'terminating'])
            ->when($excludeEmploymentId !== null, fn ($q) => $q->where('e.id', '<>', $excludeEmploymentId))
            ->distinct()->pluck('a.employment_id')->map(fn ($id) => (string) $id)->all();

        $ranges = [];
        if ($members !== []) {
            $vac = DB::table('vacation_requests')->whereIn('employment_id', $members)->whereNull('deleted_at')->whereIn('kind', ['leave', 'advance'])
                ->whereIn('status', VacationRequestStateMachine::ACTIVE_STATES)->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
                ->get(['employment_id', 'starts_on', 'ends_on']);
            $abs = DB::table('absence_requests')->whereIn('employment_id', $members)->whereNull('deleted_at')
                ->whereIn('status', self::ABSENCE_ACTIVE)->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
                ->get(['employment_id', 'starts_on', 'ends_on']);
            $ranges = $vac->concat($abs)->all();
        }

        $counts = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $date = $d->toDateString();
            $who = [];
            foreach ($ranges as $r) {
                if ($date >= substr((string) $r->starts_on, 0, 10) && $date <= substr((string) $r->ends_on, 0, 10)) {
                    $who[(string) $r->employment_id] = true;
                }
            }
            $counts[$date] = count($who);
        }

        return $counts;
    }

    /**
     * Agrupa en tramos consecutivos los días que superan el límite.
     *
     * @param  array<string, int>  $counts
     * @return list<array{from: string, to: string, count: int, limit: int}>
     */
    private function ranges(array $counts, int $limit): array
    {
        $out = [];
        $current = null;
        foreach ($counts as $date => $n) {
            if ($n > $limit) {
                if ($current !== null && CarbonImmutable::parse($current['to'])->addDay()->toDateString() === $date) {
                    $current['to'] = $date;
                    $current['count'] = max($current['count'], $n);
                } else {
                    if ($current !== null) {
                        $out[] = $current;
                    }
                    $current = ['from' => $date, 'to' => $date, 'count' => $n, 'limit' => $limit];
                }
            }
        }
        if ($current !== null) {
            $out[] = $current;
        }

        return $out;
    }

    private function limit(string $legalEntityId): int
    {
        $value = $this->parameters->value('vacaciones.maximo_ausentes_simultaneos', $legalEntityId);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private function unitsOf(?string $personId, string $asOf): array
    {
        if ($personId === null) {
            return [];
        }

        /** @var list<string> */
        return DB::table('employment_assignments as a')->join('employments as e', 'e.id', '=', 'a.employment_id')
            ->where('e.person_id', $personId)->whereNull('e.deleted_at')->whereIn('e.status', ['active', 'suspended', 'terminating'])
            ->where('a.valid_from', '<=', $asOf)->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $asOf))
            ->pluck('a.org_unit_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
    }
}
