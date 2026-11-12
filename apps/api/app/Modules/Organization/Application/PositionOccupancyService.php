<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Infrastructure\Models\Position;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS-1 · POS-2 · Ocupación de las posiciones a partir de las asignaciones vigentes.
 *
 * - Ocupada cuando sus ocupantes vigentes llenan los cupos; vacante cuando quedan cupos libres
 *   (RN-POS-04: al cesar o trasladarse el ocupante, la posición vuelve a vacante en la fecha efectiva).
 * - RN-POS-02 y RN-POS-03 al asignar; la base vuelve a comprobar los cupos con un disparador.
 */
final class PositionOccupancyService implements PositionOccupancy
{
    /** Estados que admiten ocupantes. */
    private const ASSIGNABLE = ['approved', 'open', 'recruiting', 'filled'];

    public function __construct(private readonly PositionService $positions) {}

    public function assertAssignable(string $positionId, string $from, ?string $to = null, ?string $employmentId = null): void
    {
        $position = Position::query()->find($positionId);
        if (! $position instanceof Position) {
            throw ValidationException::withMessages(['position_id' => [(string) trans('organization.positions.not_found')]]);
        }
        if (! in_array($position->status, self::ASSIGNABLE, true)) {
            throw ValidationException::withMessages(['position_id' => [(string) trans('organization.positions.not_assignable_'.$position->status)]]);
        }
        $day = EffectiveDated::day($from);
        if ($position->valid_to !== null && $position->valid_to->toDateString() < $day) {
            throw ValidationException::withMessages(['position_id' => [(string) trans('organization.positions.expired')]]);
        }
        $used = $this->positions->countOccupants($position->id, $day, $employmentId, $to === null ? null : EffectiveDated::day($to));
        if ($to === null) {
            // Sin fecha de fin cuentan también las asignaciones futuras de otros.
            $used = max($used, $this->openEnded($position->id, $day, $employmentId));
        }
        if ($used >= $position->seats) {
            throw ValidationException::withMessages(['position_id' => [(string) trans('organization.positions.no_free_seat', ['seats' => $position->seats])]]);
        }
    }

    public function occupiedSeats(string $positionId, ?string $date = null): int
    {
        return $this->positions->countOccupants($positionId, EffectiveDated::day($date ?? now()));
    }

    public function sync(string $positionId, ?string $userId = null): ?string
    {
        $position = Position::query()->find($positionId);
        if (! $position instanceof Position) {
            return null;
        }
        $occupied = $this->occupiedSeats($position->id);
        $full = $occupied >= $position->seats;
        if ($full && in_array($position->status, ['approved', 'open', 'recruiting'], true)) {
            $position = $this->positions->transition($position, 'fill', null, null, $userId);
        } elseif (! $full && $position->status === 'filled') {
            $position = $this->positions->transition($position, 'vacate', null, null, $userId);
        }

        return $position->status;
    }

    /** Asignaciones de otros que siguen vigentes en algún momento desde la fecha (sin fin). */
    private function openEnded(string $positionId, string $day, ?string $employmentId): int
    {
        return DB::table('employment_assignments')->where('position_id', $positionId)
            ->when($employmentId !== null, fn ($q) => $q->where('employment_id', '<>', $employmentId))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->count();
    }
}
