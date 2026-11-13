<?php

namespace App\Modules\CoreHR\Infrastructure\Labor;

use App\Modules\CoreHR\Application\AssignmentMovements;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Labor\AssignmentChanges;

/**
 * CON-3 · RN-CON-08 · Una adenda firmada que cambia la asignación se registra como movimiento con las
 * mismas reglas que COL-5 (cierra la vigente, abre otra, sincroniza posiciones, emite employee.updated).
 */
final class AssignmentChangesAdapter implements AssignmentChanges
{
    public function __construct(private readonly AssignmentMovements $movements) {}

    public function apply(string $employmentId, array $changes, string $effectiveOn, string $reason, ?string $userId): string
    {
        $employment = Employment::query()->findOrFail($employmentId);
        $input = array_intersect_key($changes, array_flip(self::FIELDS));
        $assignment = $this->movements->register($employment, $input + ['valid_from' => $effectiveOn, 'reason' => $reason], $userId);

        return (string) ($assignment['personnel_action_id'] ?? $assignment['id']);
    }
}
