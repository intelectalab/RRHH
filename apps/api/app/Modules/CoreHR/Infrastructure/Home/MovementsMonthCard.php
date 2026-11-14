<?php

namespace App\Modules\CoreHR\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · Ingresos y salidas del mes en curso.
 *
 * - hires_month: relaciones con fecha de ingreso en el mes (sin las anuladas).
 * - terminations_month: ceses con fecha en el mes (en proceso o ya efectivos).
 */
final class MovementsMonthCard implements HomeCard
{
    private function __construct(private readonly string $kind) {}

    public static function hires(): self
    {
        return new self('hires_month');
    }

    public static function terminations(): self
    {
        return new self('terminations_month');
    }

    public function key(): string
    {
        return $this->kind;
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
        return $this->kind === 'hires_month' ? 20 : 30;
    }

    public function compute(HomeContext $context): array
    {
        $from = $context->today->startOfMonth()->toDateString();
        $to = $context->today->endOfMonth()->toDateString();
        $hires = $this->kind === 'hires_month';
        $column = $hires ? 'e.hire_date' : 'e.termination_date';

        $query = HomeSql::employments()->whereBetween($column, [$from, $to]);
        $hires ? $query->where('e.status', '!=', 'cancelled') : $query->whereIn('e.status', ['terminating', 'terminated']);
        $context->restrict($query, 'e.id', $this->permission());
        $rows = (clone $query)->orderBy($column)->limit(8)
            ->get(['e.id as employment_id', DB::raw(HomeSql::PERSON_NAME.' as name'), "{$column} as date"]);
        $filter = $hires ? 'hired' : 'terminated';

        return [
            'value' => (clone $query)->count(),
            'link' => "/personas/colaboradores?filter[{$filter}_from]={$from}&filter[{$filter}_to]={$to}",
            'items' => HomeSql::items($rows),
            'meta' => ['from' => $from, 'to' => $to],
        ];
    }
}
