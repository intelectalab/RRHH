<?php

namespace App\Modules\CoreHR\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · MSS-1 · Dotación: relaciones vigentes (activas, suspendidas y en proceso de cese), con el
 * desglose por estado. En el tablero del manager, su equipo directo e indirecto.
 */
final class HeadcountCard implements HomeCard
{
    public function key(): string
    {
        return 'headcount';
    }

    public function permission(): string
    {
        return 'people.employments.view';
    }

    public function audiences(): array
    {
        return [self::HR, self::MANAGER];
    }

    public function order(): int
    {
        return 10;
    }

    public function compute(HomeContext $context): array
    {
        $query = HomeSql::employments()->whereIn('e.status', HomeSql::OPEN);
        $context->restrict($query, 'e.id', $this->permission());
        $byStatus = [];
        foreach ($query->groupBy('e.status')->get(['e.status', DB::raw('count(*) as n')]) as $row) {
            $byStatus[(string) $row->status] = (int) $row->n;
        }

        return [
            'value' => array_sum($byStatus),
            'link' => $context->link('/personas/colaboradores?filter[status]=active,suspended,terminating', '/mi-equipo'),
            'meta' => ['by_status' => (object) $byStatus],
        ];
    }
}
