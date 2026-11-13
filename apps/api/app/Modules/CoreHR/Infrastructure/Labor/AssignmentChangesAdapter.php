<?php

namespace App\Modules\CoreHR\Infrastructure\Labor;

use App\Modules\CoreHR\Application\PersonnelActions;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Labor\AssignmentChanges;

/**
 * CON-3 · RN-CON-08 · Una adenda firmada que cambia la asignación se registra como movimiento de personal
 * (HIS-2): la única vía para cambiar la asignación. La adenda ya fue aprobada y firmada, así que no vuelve
 * a pasar por el flujo; con fecha futura queda programada (HIS-4) y con fecha de hoy o pasada se aplica.
 */
final class AssignmentChangesAdapter implements AssignmentChanges
{
    public function __construct(private readonly PersonnelActions $actions) {}

    public function apply(string $employmentId, array $changes, string $effectiveOn, string $reason, ?string $userId): string
    {
        $employment = Employment::query()->findOrFail($employmentId);
        $action = $this->actions->register($employment, [
            'effective_on' => $effectiveOn,
            'reason' => $reason,
            'changes' => array_intersect_key($changes, array_flip(self::FIELDS)),
        ], $userId, canCreate: true, canCorrect: false);

        return (string) $action->id;
    }
}
