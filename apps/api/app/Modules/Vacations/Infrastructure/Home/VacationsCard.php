<?php

namespace App\Modules\Vacations\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · MSS-1 · Vacaciones: quiénes están de vacaciones hoy y quiénes salen en los próximos días
 * (aprobadas o en goce, VAC-3). El calendario del equipo (VAC-4) explica el número.
 */
final class VacationsCard implements HomeCard
{
    public const UPCOMING_DAYS = 14;

    public function key(): string
    {
        return 'on_vacation';
    }

    public function permission(): string
    {
        return 'time.vacation_requests.view';
    }

    public function audiences(): array
    {
        return [self::HR, self::MANAGER];
    }

    public function order(): int
    {
        return 65;
    }

    public function compute(HomeContext $context): array
    {
        $today = $context->today->toDateString();
        $until = $context->today->addDays(self::UPCOMING_DAYS)->toDateString();
        $base = HomeSql::employments()->join('vacation_requests as v', 'v.employment_id', '=', 'e.id')
            ->whereNull('v.deleted_at')->whereIn('v.status', ['approved', 'in_progress']);
        $context->restrict($base, 'e.id', $this->permission());

        $now = (clone $base)->where('v.starts_on', '<=', $today)->where('v.ends_on', '>=', $today);
        $upcoming = (clone $base)->where('v.starts_on', '>', $today)->where('v.starts_on', '<=', $until);
        $rows = (clone $upcoming)->orderBy('v.starts_on')->limit(8)
            ->get(['e.id as employment_id', DB::raw(HomeSql::PERSON_NAME.' as name'), 'v.starts_on as date']);

        return [
            'value' => (clone $now)->distinct()->count('e.id'),
            'link' => '/tiempo/vacaciones',
            'items' => HomeSql::items($rows),
            'meta' => ['upcoming' => (clone $upcoming)->distinct()->count('e.id'), 'upcoming_days' => self::UPCOMING_DAYS],
        ];
    }
}
