<?php

namespace App\Modules\CoreHR\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · Por ingresar (onboarding): relaciones registradas con fecha de ingreso futura, que se
 * activarán solas en su fecha (RN-COL-04).
 */
final class PendingStartCard implements HomeCard
{
    public function key(): string
    {
        return 'pending_start';
    }

    public function permission(): string
    {
        return 'people.employments.view';
    }

    public function audiences(): array
    {
        // Solo RRHH: quien aún no ingresa no tiene asignación vigente, así que no está en la línea de reporte.
        return [self::HR];
    }

    public function order(): int
    {
        return 40;
    }

    public function compute(HomeContext $context): array
    {
        $query = HomeSql::employments()->where('e.status', 'pending');
        $context->restrict($query, 'e.id', $this->permission());
        $count = (clone $query)->count();
        $rows = $query->orderBy('e.hire_date')->limit(8)
            ->get(['e.id as employment_id', DB::raw(HomeSql::PERSON_NAME.' as name'), 'e.hire_date as date']);

        return [
            'value' => $count,
            'link' => '/personas/colaboradores?filter[status]=pending',
            'items' => HomeSql::items($rows),
        ];
    }
}
