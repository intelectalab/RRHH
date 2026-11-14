<?php

namespace App\Modules\Organization\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Permissions\TeamLine;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · MSS-1 · MSS-2 · Plazas vacantes: posiciones aprobadas, abiertas o en reclutamiento (POS-2).
 * En RRHH, las de las empresas de su alcance; en el tablero del manager, las que dependen de su posición
 * directa o indirectamente (línea de reporte por posiciones).
 */
final class VacantPositionsCard implements HomeCard
{
    public const VACANT = ['approved', 'open', 'recruiting'];

    public function __construct(private readonly TeamLine $team) {}

    public function key(): string
    {
        return 'vacant_positions';
    }

    public function permission(): string
    {
        return 'org.positions.view';
    }

    public function audiences(): array
    {
        return [self::HR, self::MANAGER];
    }

    public function order(): int
    {
        return 75;
    }

    public function compute(HomeContext $context): ?array
    {
        $query = DB::table('positions as p')->whereNull('p.deleted_at')->whereIn('p.status', self::VACANT);
        if ($context->isManager()) {
            $ids = $this->team->positionsUnder($context->userId, $context->today->toDateString());
            if ($ids === []) {
                return null;
            }
            $query->whereIn('p.id', $ids);
        } else {
            $context->restrictLegalEntity($query, 'p.legal_entity_id', $this->permission());
        }
        $count = (clone $query)->count();
        $items = [];
        foreach ($query->orderBy('p.title')->limit(8)->get(['p.id', 'p.title', 'p.code', 'p.status']) as $row) {
            $items[] = ['position_id' => (string) $row->id, 'name' => (string) $row->title, 'detail' => (string) $row->status, 'link' => '/organizacion/posiciones?q='.rawurlencode((string) $row->code)];
        }

        return [
            'value' => $count,
            'link' => $context->link('/organizacion/posiciones?filter[status]=approved,open,recruiting', '/mi-equipo'),
            'items' => $items,
        ];
    }
}
