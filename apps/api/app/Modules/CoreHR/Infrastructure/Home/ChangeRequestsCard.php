<?php

namespace App\Modules\CoreHR\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · Solicitudes de cambio de datos pendientes de revisión de RRHH (RN-COL-06), de las personas
 * con alguna relación dentro del alcance del usuario.
 */
final class ChangeRequestsCard implements HomeCard
{
    public function key(): string
    {
        return 'pending_change_requests';
    }

    public function permission(): string
    {
        return 'people.change_requests.approve';
    }

    public function audiences(): array
    {
        return [self::HR];
    }

    public function order(): int
    {
        return 70;
    }

    public function compute(HomeContext $context): array
    {
        $people = DB::table('employments as e')->whereNull('e.deleted_at')->select('e.person_id');
        $context->restrict($people, 'e.id', $this->permission());
        $count = DB::table('profile_change_requests as r')->where('r.status', 'pending')->whereIn('r.person_id', $people)->count();

        return [
            'value' => $count,
            'link' => '/personas/cambios-de-datos',
            'tone' => $count > 0 ? 'warning' : null,
        ];
    }
}
