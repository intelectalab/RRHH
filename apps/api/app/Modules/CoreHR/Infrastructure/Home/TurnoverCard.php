<?php

namespace App\Modules\CoreHR\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * COL-12 · Rotación de los últimos 12 meses: ceses efectivos con fecha en ese periodo sobre la dotación
 * promedio (vigente hoy más los cesados del periodo, entre dos), en porcentaje con un decimal. La tarjeta
 * explica su cálculo en meta para que el número no sea una caja negra.
 */
final class TurnoverCard implements HomeCard
{
    public function key(): string
    {
        return 'turnover_12m';
    }

    public function permission(): string
    {
        return 'people.employments.view';
    }

    public function audiences(): array
    {
        return [self::HR];
    }

    public function order(): int
    {
        return 85;
    }

    public function compute(HomeContext $context): array
    {
        $to = $context->today->toDateString();
        $from = $context->today->subYear()->addDay()->toDateString();

        $exits = HomeSql::employments()->where('e.status', 'terminated')->whereBetween('e.termination_date', [$from, $to]);
        $context->restrict($exits, 'e.id', $this->permission());
        $headcount = HomeSql::employments()->whereIn('e.status', HomeSql::OPEN);
        $context->restrict($headcount, 'e.id', $this->permission());

        $left = $exits->count();
        $current = $headcount->count();
        $average = BigDecimal::of($current + $current + $left)->dividedBy(2, 2, RoundingMode::HALF_UP);
        $rate = $average->isZero() ? BigDecimal::zero()->toScale(1) : BigDecimal::of($left)->multipliedBy(100)->dividedBy($average, 1, RoundingMode::HALF_UP);

        return [
            'value' => (string) $rate,
            'link' => "/personas/colaboradores?filter[terminated_from]={$from}&filter[terminated_to]={$to}",
            'meta' => ['unit' => 'percent', 'exits' => $left, 'average_headcount' => (string) $average, 'from' => $from, 'to' => $to],
        ];
    }
}
