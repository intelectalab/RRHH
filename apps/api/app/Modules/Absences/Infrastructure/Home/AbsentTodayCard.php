<?php

namespace App\Modules\Absences\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · MSS-1 · Ausentes hoy por una ausencia aprobada o en curso (AUS-2). La tarjeta no muestra el
 * tipo ni el motivo (pueden ser confidenciales, RN-AUS-05): solo quién y hasta cuándo.
 */
final class AbsentTodayCard implements HomeCard
{
    public function key(): string
    {
        return 'absent_today';
    }

    public function permission(): string
    {
        return 'time.absences.view';
    }

    public function audiences(): array
    {
        return [self::HR, self::MANAGER];
    }

    public function order(): int
    {
        return 60;
    }

    public function compute(HomeContext $context): array
    {
        $today = $context->today->toDateString();
        $query = HomeSql::employments()->join('absence_requests as r', 'r.employment_id', '=', 'e.id')
            ->whereNull('r.deleted_at')->whereIn('r.status', ['approved', 'in_progress'])
            ->where('r.starts_on', '<=', $today)->where('r.ends_on', '>=', $today);
        $context->restrict($query, 'e.id', $this->permission());

        $count = (clone $query)->distinct()->count('e.id');
        $rows = $query->orderBy('r.ends_on')->limit(8)->get(['e.id as employment_id', DB::raw(HomeSql::PERSON_NAME.' as name'), 'r.ends_on as date']);

        return [
            'value' => $count,
            'link' => $context->link('/tiempo/ausencias', '/tiempo/vacaciones'),
            'items' => HomeSql::items($rows),
        ];
    }
}
