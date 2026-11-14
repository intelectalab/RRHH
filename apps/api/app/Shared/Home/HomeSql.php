<?php

namespace App\Shared\Home;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Piezas comunes de las consultas de las tarjetas.
 */
final class HomeSql
{
    /** Relaciones laborales vigentes (cuentan para la dotación). */
    public const OPEN = ['active', 'suspended', 'terminating'];

    /** Nombre visible de la persona `p` (nombre preferido o primer nombre y apellido paterno). */
    public const PERSON_NAME = "concat_ws(' ', coalesce(nullif(p.preferred_name, ''), split_part(p.first_names, ' ', 1)), p.paternal_surname)";

    /** Relaciones con su persona (sin anonimizadas ni borradas). */
    public static function employments(): Builder
    {
        return DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereNull('e.deleted_at')->whereNull('p.anonymized_at');
    }

    /**
     * @param  iterable<\stdClass>  $rows  filas con employment_id, name y opcionalmente date / detail
     * @return list<array<string, mixed>>
     */
    public static function items(iterable $rows, int $limit = 8): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = array_filter([
                'employment_id' => (string) $row->employment_id,
                'name' => (string) $row->name,
                'date' => isset($row->date) ? substr((string) $row->date, 0, 10) : null,
                'detail' => isset($row->detail) ? (string) $row->detail : null,
                'link' => '/personas/colaboradores/'.$row->employment_id,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }
}
