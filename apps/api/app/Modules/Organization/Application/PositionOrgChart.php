<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Infrastructure\Models\Position;
use Illuminate\Support\Facades\DB;

/**
 * POS-7 · Organigrama por posiciones a una fecha.
 *
 * Cada nodo es una plaza vigente ese día (sin las propuestas ni las cerradas), colgada de su posición jefe
 * (la explícita o, si no tiene, el responsable de su unidad o de la unidad superior, como en POS-1). El
 * estado que se dibuja sale de las asignaciones de ese día: ocupada si sus cupos están llenos; si no,
 * congelada, en reclutamiento o vacante según la plaza. Por rama (la plaza y todo lo que cuelga de ella)
 * se suman cupos aprobados, ocupados, vacantes y congelados, y el costo proyectado mensual (RN-POS-07) por
 * moneda, que solo viaja con org.positions.budget.view (lo quita SensitiveFields).
 */
final class PositionOrgChart
{
    /** Estados que se dibujan (las propuestas aún no son plazas; las cerradas ya no). */
    private const SHOWN = ['approved', 'open', 'recruiting', 'filled', 'frozen'];

    public function __construct(
        private readonly PositionService $positions,
        private readonly HeadcountService $headcount,
    ) {}

    /**
     * @param  array<int, string>|null  $entityIds  alcance (null = todas)
     * @return list<array<string, mixed>>
     */
    public function nodes(string $date, ?array $entityIds, ?string $legalEntityId, ?string $orgUnitId): array
    {
        $query = Position::query()->whereIn('status', self::SHOWN)
            ->where('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date));
        if ($entityIds !== null) {
            $query->whereIn('legal_entity_id', $entityIds);
        }
        if ($legalEntityId !== null) {
            $query->where('legal_entity_id', $legalEntityId);
        }
        if ($orgUnitId !== null) {
            $query->whereIn('org_unit_id', $this->subtree($orgUnitId));
        }
        /** @var array<int, Position> $list */
        $list = $query->orderBy('code')->get()->all();
        if ($list === []) {
            return [];
        }
        $ids = array_map(fn (Position $p) => $p->id, $list);
        $present = [];
        foreach ($list as $p) {
            $present[$p->id] = $p;
        }
        $occupants = $this->positions->occupantsOf($ids, $date);
        $costs = $this->headcount->projectedCosts($list, $date);
        $profiles = DB::table('job_profiles')->whereIn('id', array_unique(array_map(fn (Position $p) => $p->job_profile_id, $list)))->pluck('name', 'id');
        $units = DB::table('org_units')->whereIn('id', array_unique(array_map(fn (Position $p) => $p->org_unit_id, $list)))->pluck('name', 'id');

        $nodes = [];
        foreach ($list as $p) {
            $parent = $this->positions->effectiveManagerId($p);
            $taken = count($occupants[$p->id] ?? []);
            $state = $taken >= $p->seats ? 'filled' : match ($p->status) {
                'frozen' => 'frozen',
                'recruiting' => 'recruiting',
                default => 'vacant',
            };
            $nodes[$p->id] = [
                'id' => $p->id,
                'parent_id' => $parent !== null && isset($present[$parent]) ? $parent : null,
                'code' => $p->code,
                'title' => $p->title ?? (string) ($profiles[$p->job_profile_id] ?? ''),
                'job_profile_name' => isset($profiles[$p->job_profile_id]) ? (string) $profiles[$p->job_profile_id] : null,
                'org_unit_id' => $p->org_unit_id,
                'org_unit_name' => isset($units[$p->org_unit_id]) ? (string) $units[$p->org_unit_id] : null,
                'legal_entity_id' => $p->legal_entity_id,
                'status' => $p->status,
                'chart_state' => $state,
                'is_critical' => $p->is_critical,
                'is_implicit' => (bool) $p->is_implicit,
                'seats' => $p->seats,
                'occupied_seats' => min($taken, $p->seats),
                'occupants' => array_map(fn (array $o) => ['employment_id' => $o['employment_id'], 'employee_code' => $o['employee_code'], 'person_name' => $o['person_name']], $occupants[$p->id] ?? []),
                'own' => [
                    'approved_seats' => $p->seats,
                    'occupied_seats' => min($taken, $p->seats),
                    'frozen_seats' => $p->status === 'frozen' ? max(0, $p->seats - $taken) : 0,
                    'cost' => $costs[$p->id] ?? null,
                ],
            ];
        }

        return $this->withBranches($nodes);
    }

    /**
     * Suma, para cada nodo, su rama completa (él y sus descendientes).
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function withBranches(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $id => $n) {
            if ($n['parent_id'] !== null) {
                $children[(string) $n['parent_id']][] = (string) $id;
            }
        }
        $memo = [];
        $sum = function (string $id, array $seen) use (&$sum, &$memo, $nodes, $children): array {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            $own = $nodes[$id]['own'];
            $b = ['positions' => 1, 'approved_seats' => $own['approved_seats'], 'occupied_seats' => $own['occupied_seats'], 'frozen_seats' => $own['frozen_seats'], 'cost' => [], 'positions_without_cost' => 0];
            if (is_array($own['cost'])) {
                $b['cost'][$own['cost']['currency']] = $own['cost']['amount'];
            } else {
                $b['positions_without_cost'] = 1;
            }
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                if (isset($seen[$child])) {
                    continue; // una línea de reporte con ciclo no se suma dos veces
                }
                $c = $sum($child, $seen);
                foreach (['positions', 'approved_seats', 'occupied_seats', 'frozen_seats', 'positions_without_cost'] as $k) {
                    $b[$k] += $c[$k];
                }
                foreach ($c['cost'] as $currency => $amount) {
                    $b['cost'][$currency] = self::add($b['cost'][$currency] ?? '0', $amount);
                }
            }

            return $memo[$id] = $b;
        };

        $out = [];
        foreach ($nodes as $id => $n) {
            $b = $sum((string) $id, []);
            unset($n['own']);
            $n['branch'] = [
                'positions' => $b['positions'],
                'approved_seats' => $b['approved_seats'],
                'occupied_seats' => $b['occupied_seats'],
                'vacant_seats' => $b['approved_seats'] - $b['occupied_seats'] - $b['frozen_seats'],
                'frozen_seats' => $b['frozen_seats'],
                'positions_without_cost' => $b['positions_without_cost'],
            ];
            $n['branch_projected_cost'] = array_map(fn ($c, $a) => ['amount' => $a, 'currency' => (string) $c], array_keys($b['cost']), array_values($b['cost']));
            $out[] = $n;
        }

        return $out;
    }

    /** Suma exacta de dos decimales en texto (dos cifras decimales, como los costos proyectados). */
    private static function add(string $a, string $b): string
    {
        $toCents = function (string $v): int {
            $neg = str_starts_with($v, '-');
            $v = ltrim($v, '-');
            [$int, $dec] = array_pad(explode('.', $v, 2), 2, '');
            $cents = (int) $int * 100 + (int) str_pad(substr($dec, 0, 2), 2, '0');

            return $neg ? -$cents : $cents;
        };
        $c = $toCents($a) + $toCents($b);
        $sign = $c < 0 ? '-' : '';
        $c = abs($c);

        return $sign.intdiv($c, 100).'.'.str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return list<string> la unidad y sus subunidades vigentes */
    private function subtree(string $unitId): array
    {
        $all = DB::table('org_units')->whereNull('deleted_at')->get(['id', 'parent_id']);
        $children = [];
        foreach ($all as $u) {
            if ($u->parent_id !== null) {
                $children[(string) $u->parent_id][] = (string) $u->id;
            }
        }
        $out = [];
        $stack = [$unitId];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = true;
            foreach ($children[$id] ?? [] as $c) {
                $stack[] = $c;
            }
        }

        return array_keys($out);
    }
}
