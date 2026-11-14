<?php

namespace App\Shared\Permissions;

use Illuminate\Support\Facades\DB;

/**
 * MSS-1 · RN-MSS-01 · Equipo de un usuario según la línea de reporte vigente: quienes le reportan directa o
 * indirectamente. Es la misma regla que el alcance «equipo» de SEG-10 (jefe por posición, con excepción por
 * relación cuando la asignación indica manager_employment_id), con la profundidad de cada persona.
 *
 * Se calcula en cada consulta: un cambio de estructura o de jefe aplica solo.
 */
final class TeamLine
{
    /**
     * Relaciones laborales del equipo con su nivel (1 = directo, 2 o más = indirecto).
     *
     * @return array<string, int> employment_id → nivel
     */
    public function members(string $userId, string $asOf, bool $includeIndirect): array
    {
        $current = '(a.valid_from <= ?::date AND (a.valid_to IS NULL OR a.valid_to >= ?::date))';
        $sql = 'WITH RECURSIVE mine AS ('
            .'SELECT e.id AS emp_id, a.position_id AS pos_id FROM employments e JOIN users u ON u.person_id = e.person_id '
            ."JOIN employment_assignments a ON a.employment_id = e.id AND {$current} "
            ."WHERE u.id = ? AND e.status IN ('active','suspended','terminating') AND e.deleted_at IS NULL"
            .'), team AS ('
            .'SELECT emp_id, pos_id, 0 AS depth FROM mine '
            .'UNION '
            .'SELECT a.employment_id, a.position_id, t.depth + 1 FROM employment_assignments a '
            .'JOIN employments e2 ON e2.id = a.employment_id AND e2.deleted_at IS NULL '
            .'LEFT JOIN positions p ON p.id = a.position_id '
            .'JOIN team t ON (a.manager_employment_id = t.emp_id OR (a.manager_employment_id IS NULL AND p.reports_to_position_id = t.pos_id)) '
            ."WHERE {$current} AND t.depth < 20"
            .') SELECT emp_id, MIN(depth) AS depth FROM team WHERE emp_id NOT IN (SELECT emp_id FROM mine) GROUP BY emp_id';

        $rows = DB::select($sql, [$asOf, $asOf, $userId, $asOf, $asOf]);
        $out = [];
        foreach ($rows as $row) {
            $depth = (int) $row->depth;
            if ($includeIndirect || $depth === 1) {
                $out[(string) $row->emp_id] = $depth;
            }
        }

        return $out;
    }

    /** ¿El usuario tiene a alguien a su cargo hoy? (para ofrecer la vista de manager). */
    public function hasReports(string $userId, string $asOf): bool
    {
        return $this->members($userId, $asOf, false) !== [];
    }

    /**
     * MSS-2 · Posiciones que dependen (directa o indirectamente) de las posiciones que ocupa hoy el usuario.
     *
     * @return list<string>
     */
    public function positionsUnder(string $userId, string $asOf): array
    {
        $rows = DB::select(
            'WITH RECURSIVE own AS ('
            .'SELECT a.position_id AS id FROM employments e JOIN users u ON u.person_id = e.person_id '
            .'JOIN employment_assignments a ON a.employment_id = e.id AND a.valid_from <= ?::date AND (a.valid_to IS NULL OR a.valid_to >= ?::date) '
            ."WHERE u.id = ? AND e.status IN ('active','suspended','terminating') AND e.deleted_at IS NULL AND a.position_id IS NOT NULL"
            .'), tree AS (SELECT id, 0 AS depth FROM own UNION SELECT p.id, t.depth + 1 FROM positions p JOIN tree t ON p.reports_to_position_id = t.id WHERE p.deleted_at IS NULL AND t.depth < 20) '
            .'SELECT DISTINCT id FROM tree WHERE id NOT IN (SELECT id FROM own)',
            [$asOf, $asOf, $userId],
        );

        return array_values(array_map(fn ($r) => (string) $r->id, $rows));
    }
}
