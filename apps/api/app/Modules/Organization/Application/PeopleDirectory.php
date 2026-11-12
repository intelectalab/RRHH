<?php

namespace App\Modules\Organization\Application;

use App\Shared\Permissions\DataScope;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ORG-7 · Directorio de personas y línea de reporte (también la usa el organigrama, ORG-6).
 *
 * Se arma sobre la relación laboral y su asignación vigente a una fecha (C-03): puesto, unidad, sede y
 * jefe. RN-ORG-08: de cada persona solo salen nombre, puesto, unidad, sede, empresa y el contacto
 * laboral que la organización publica (parámetro directorio.campos_contacto). Ningún dato personal
 * ni sensible (documento, correo o celular personal, fecha de nacimiento, sueldo) se selecciona.
 *
 * Jefe directo: el de la asignación (manager_employment_id) o, si no lo tiene, quien ocupa ese día la
 * posición jefe de su posición (positions.reports_to_position_id). Se calcula en cada consulta, así
 * que cambiar un jefe o un ocupante se refleja sin pasos adicionales (RN-ORG-03).
 *
 * Punto de extensión para COL: cuando exista la ficha del colaborador, sus datos de contacto laboral
 * se escriben en employments.work_*; el directorio no cambia.
 */
final class PeopleDirectory
{
    /** Datos de contacto laboral que la organización puede publicar (opciones del parámetro). */
    public const CONTACT_FIELDS = ['work_email', 'work_phone', 'work_phone_ext'];

    public const PERMISSION = 'people.directory.view';

    /** Tope de nodos del organigrama por personas en una sola respuesta. */
    private const MAX_PEOPLE = 5000;

    public function __construct(
        private readonly ParameterResolver $parameters,
        private readonly DataScope $scope,
    ) {}

    /**
     * @return list<string>
     */
    public function contactFields(): array
    {
        $value = $this->parameters->value('directorio.campos_contacto');

        return array_values(array_intersect(self::CONTACT_FIELDS, is_array($value) ? $value : []));
    }

    /**
     * Página del directorio a hoy.
     *
     * @param  array{q?: string|null, org_unit_id?: string|null, site_id?: string|null, job_profile_id?: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function page(Request $request, array $filters, int $page, int $perPage): array
    {
        $day = now()->toDateString();
        $query = $this->visible($request, $day);

        if (($filters['org_unit_id'] ?? null) !== null) {
            $query->whereIn('a.org_unit_id', $this->unitWithDescendants((string) $filters['org_unit_id'], $day));
        }
        if (($filters['site_id'] ?? null) !== null) {
            $query->where('a.site_id', $filters['site_id']);
        }
        if (($filters['job_profile_id'] ?? null) !== null) {
            $query->where('a.job_profile_id', $filters['job_profile_id']);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
            $query->where(function (Builder $w) use ($like): void {
                $w->whereRaw("lower(concat_ws(' ', p.preferred_name, p.first_names, p.paternal_surname, p.maternal_surname)) LIKE ?", [$like])
                    ->orWhereRaw('lower(j.name) LIKE ?', [$like]);
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('p.paternal_surname')->orderBy('p.first_names')->orderBy('e.id')
            ->forPage($page, $perPage)->get($this->columns($day));
        $managers = $this->reportingLines($day);
        $fields = $this->contactFields();

        return [
            'data' => array_values($rows->map(fn (\stdClass $r) => $this->present($r, $managers[(string) $r->employment_id] ?? null, $fields))->all()),
            'total' => $total,
        ];
    }

    /**
     * Tarjeta de contacto con su equipo: jefe directo, compañeros (mismo jefe) y reportes directos.
     * Null si la relación no existe, no está vigente o está fuera del alcance del usuario.
     *
     * @return array<string, mixed>|null
     */
    public function card(Request $request, string $employmentId): ?array
    {
        $day = now()->toDateString();
        $managers = $this->reportingLines($day);
        $fields = $this->contactFields();
        $self = $this->visible($request, $day)->where('e.id', $employmentId)->first($this->columns($day));
        if (! $self instanceof \stdClass) {
            return null;
        }
        $managerId = $managers[$employmentId] ?? null;
        $peers = $managerId === null ? [] : array_keys(array_filter($managers, fn ($m, $e) => $m === $managerId && $e !== $employmentId, ARRAY_FILTER_USE_BOTH));
        $reports = array_keys(array_filter($managers, fn ($m) => $m === $employmentId));
        $related = array_values(array_unique(array_merge($managerId === null ? [] : [$managerId], $peers, $reports)));
        $others = $related === [] ? collect() : $this->visible($request, $day)->whereIn('e.id', $related)
            ->orderBy('p.paternal_surname')->orderBy('p.first_names')->get($this->columns($day))->keyBy('employment_id');
        $pick = fn (array $ids): array => collect($ids)->map(fn ($id) => $others->get($id))->filter()
            ->map(fn (\stdClass $r) => $this->present($r, $managers[(string) $r->employment_id] ?? null, $fields))->values()->all();

        return $this->present($self, $managerId, $fields) + [
            'manager' => $managerId !== null && $others->has($managerId) ? $this->present($others->get($managerId), $managers[$managerId] ?? null, $fields) : null,
            'peers' => $pick($peers),
            'direct_reports' => $pick($reports),
        ];
    }

    /**
     * Personas vigentes a una fecha con su jefe directo, para el organigrama por línea de reporte.
     * El jefe fuera del alcance del usuario (o inexistente) deja a la persona como raíz; un ciclo en
     * los datos se corta para que el resultado sea siempre un bosque.
     *
     * @return list<array<string, mixed>>
     */
    public function reportingTree(Request $request, string $day): array
    {
        $rows = $this->visible($request, $day)
            ->orderBy('p.paternal_surname')->orderBy('p.first_names')->orderBy('e.id')
            ->limit(self::MAX_PEOPLE)->get($this->columns($day));
        $managers = $this->reportingLines($day);
        $fields = $this->contactFields();
        $visible = $rows->pluck('employment_id')->map(fn ($id) => (string) $id)->flip()->all();

        $parent = [];
        foreach ($rows as $r) {
            $m = $managers[(string) $r->employment_id] ?? null;
            $parent[(string) $r->employment_id] = $m !== null && isset($visible[$m]) ? $m : null;
        }
        foreach (array_keys($parent) as $id) {
            $seen = [$id => true];
            $cur = $parent[$id];
            while ($cur !== null) {
                if (isset($seen[$cur])) {
                    $parent[$id] = null;
                    break;
                }
                $seen[$cur] = true;
                $cur = $parent[$cur] ?? null;
            }
        }

        return array_values($rows->map(function (\stdClass $r) use ($parent, $fields): array {
            $id = (string) $r->employment_id;

            return ['id' => $id, 'parent_id' => $parent[$id]] + $this->present($r, $parent[$id], $fields);
        })->all());
    }

    /**
     * Ocupantes a una fecha de las posiciones indicadas: posición => persona (nombre e id de relación).
     *
     * @param  list<string>  $positionIds
     * @return array<string, array{employment_id: string, name: string}>
     */
    public function occupantsAt(array $positionIds, string $day): array
    {
        if ($positionIds === []) {
            return [];
        }
        $rows = $this->active($day)->whereIn('a.position_id', $positionIds)
            ->orderBy('a.valid_from')->get(['a.position_id', 'e.id as employment_id', 'p.preferred_name', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname']);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->position_id] ??= ['employment_id' => (string) $r->employment_id, 'name' => self::displayName($r)];
        }

        return $out;
    }

    /**
     * Personas vigentes por unidad (directas) a una fecha, dentro del alcance del usuario.
     *
     * @return array<string, int>
     */
    public function headcountByUnit(Request $request, string $day): array
    {
        return $this->visible($request, $day)->groupBy('a.org_unit_id')
            ->selectRaw('a.org_unit_id AS unit_id, count(*) AS n')->pluck('n', 'unit_id')
            ->mapWithKeys(fn ($n, $unit) => [(string) $unit => (int) $n])->all();
    }

    /**
     * Jefe directo de cada relación vigente a una fecha (toda la organización; el alcance se aplica al mostrar).
     *
     * @return array<string, string|null>
     */
    public function reportingLines(string $day): array
    {
        $sql = <<<'SQL'
            SELECT a.employment_id,
                   COALESCE(a.manager_employment_id, (
                     SELECT a2.employment_id
                       FROM positions pos
                       JOIN employment_assignments a2 ON a2.position_id = pos.reports_to_position_id
                        AND a2.valid_from <= ?::date AND (a2.valid_to IS NULL OR a2.valid_to >= ?::date)
                       JOIN employments e2 ON e2.id = a2.employment_id AND e2.deleted_at IS NULL
                        AND e2.status NOT IN ('pending', 'cancelled') AND e2.hire_date <= ?::date
                        AND (e2.termination_date IS NULL OR e2.termination_date >= ?::date)
                      WHERE pos.id = a.position_id
                      ORDER BY a2.valid_from DESC LIMIT 1)) AS manager_id
              FROM employment_assignments a
              JOIN employments e ON e.id = a.employment_id AND e.deleted_at IS NULL
               AND e.status NOT IN ('pending', 'cancelled') AND e.hire_date <= ?::date
               AND (e.termination_date IS NULL OR e.termination_date >= ?::date)
             WHERE a.valid_from <= ?::date AND (a.valid_to IS NULL OR a.valid_to >= ?::date)
            SQL;
        $out = [];
        foreach (DB::select($sql, array_fill(0, 8, $day)) as $r) {
            $id = (string) $r->employment_id;
            $m = $r->manager_id === null ? null : (string) $r->manager_id;
            $out[$id] = $m === $id ? null : $m;
        }

        return $out;
    }

    /** Relaciones vigentes a la fecha con su asignación de ese día. */
    private function active(string $day): Builder
    {
        return DB::table('employments as e')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->join('employment_assignments as a', function ($j) use ($day): void {
                $j->on('a.employment_id', '=', 'e.id')->where('a.valid_from', '<=', $day)
                    ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $day));
            })
            ->whereNull('e.deleted_at')->whereNull('p.deleted_at')->whereNull('p.anonymized_at')
            ->whereNotIn('e.status', ['pending', 'cancelled'])
            ->where('e.hire_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('e.termination_date')->orWhere('e.termination_date', '>=', $day));
    }

    /** Lo anterior, con alcance (SEG-10) y el filtro de empresa de la barra superior. */
    private function visible(Request $request, string $day): Builder
    {
        $query = $this->active($day)
            ->leftJoin('job_profiles as j', 'j.id', '=', 'a.job_profile_id')
            ->leftJoin('sites as s', 's.id', '=', 'a.site_id')
            ->leftJoin('legal_entities as le', 'le.id', '=', 'e.legal_entity_id');
        $this->scope->applyEmployment($query, $request, self::PERMISSION, 'e.id', $day);
        $selected = DataScope::selectedLegalEntity($request);
        if ($selected !== null) {
            $query->where('e.legal_entity_id', $selected);
        }

        return $query;
    }

    /**
     * Columnas permitidas: nunca datos personales ni sensibles (RN-ORG-08).
     *
     * @return list<mixed>
     */
    private function columns(string $day): array
    {
        // La fecha ya llega validada (AAAA-MM-DD); se comprueba igual antes de ponerla en SQL.
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1, 400);

        return [
            'e.id as employment_id', 'e.legal_entity_id', 'le.trade_name', 'le.legal_name',
            'p.preferred_name', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname',
            'a.job_profile_id', 'j.name as job_title', 'a.org_unit_id', 'a.site_id', 's.name as site_name',
            DB::raw("(SELECT v.name FROM org_unit_versions v WHERE v.org_unit_id = a.org_unit_id AND v.valid_from <= '{$day}'::date AND (v.valid_to IS NULL OR v.valid_to >= '{$day}'::date) LIMIT 1) AS unit_name"),
            DB::raw('(SELECT u.name FROM org_units u WHERE u.id = a.org_unit_id) AS unit_current_name'),
            'e.work_email', 'e.work_phone', 'e.work_phone_ext',
        ];
    }

    /**
     * @param  list<string>  $fields  contacto publicado por la organización
     * @return array<string, mixed>
     */
    private function present(\stdClass $r, ?string $managerId, array $fields): array
    {
        $contact = [];
        foreach ($fields as $f) {
            $contact[$f] = $r->{$f} ?? null;
        }

        return [
            'employment_id' => (string) $r->employment_id,
            'display_name' => self::displayName($r),
            'job_profile_id' => $r->job_profile_id,
            'job_title' => $r->job_title,
            'org_unit' => $r->org_unit_id === null ? null : ['id' => (string) $r->org_unit_id, 'name' => $r->unit_name ?? $r->unit_current_name],
            'site' => $r->site_id === null ? null : ['id' => (string) $r->site_id, 'name' => $r->site_name],
            'legal_entity' => ['id' => (string) $r->legal_entity_id, 'name' => $r->trade_name ?? $r->legal_name],
            'manager_employment_id' => $managerId,
            'contact' => $contact,
        ];
    }

    public static function displayName(\stdClass $r): string
    {
        $first = trim((string) ($r->preferred_name ?? '')) !== '' ? (string) $r->preferred_name : (string) $r->first_names;

        return trim(implode(' ', array_filter([$first, $r->paternal_surname ?? null, $r->maternal_surname ?? null], fn ($p) => is_string($p) && $p !== '')));
    }

    /**
     * La unidad y sus subunidades vigentes a la fecha.
     *
     * @return list<string>
     */
    private function unitWithDescendants(string $unitId, string $day): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE t AS (
              SELECT ?::uuid AS id
              UNION
              SELECT v.org_unit_id FROM org_unit_versions v JOIN t ON v.parent_id = t.id
               WHERE v.valid_from <= ?::date AND (v.valid_to IS NULL OR v.valid_to >= ?::date)
            ) SELECT id FROM t
            SQL, [$unitId, $day, $day]);

        return array_values(array_map(fn ($r) => (string) $r->id, $rows));
    }
}
