<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\AssignmentMovements;
use App\Modules\CoreHR\Application\EmploymentProfile;
use App\Modules\CoreHR\Application\EmploymentTimeline;
use App\Modules\CoreHR\Http\Controllers\Concerns\ScopedAccess;
use App\Shared\Audit\ActivityLogger;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * COL-3 · COL-5 · Ficha 360: cabecera (foto, nombre, puesto, estado, antigüedad calculada y las otras
 * relaciones de la persona para cambiar de ficha), datos laborales vigentes con «desde cuándo» y la historia
 * de la asignación, que solo cambia con un movimiento de personal (HIS-2, PersonnelActionController).
 */
final class EmploymentProfileController
{
    use ScopedAccess;

    public function __construct(
        private readonly EmploymentProfile $profile,
        private readonly AssignmentMovements $movements,
        private readonly DataScope $scope,
        private readonly EmploymentTimeline $timeline,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function profile(Request $request, string $id): JsonResponse
    {
        $employment = $this->findEmployment($request, $id, 'people.employments.view');
        app(ActivityLogger::class)->record('record.viewed', 'employment', $employment->id);

        return response()->json(['data' => $this->profile->header(
            $employment,
            $this->visibleEmploymentIds($request, $employment->person_id, 'people.employments.view'),
            $this->viewerPersonId($request),
        )]);
    }

    public function workData(Request $request, string $id): JsonResponse
    {
        $employment = $this->findEmployment($request, $id, 'people.employments.view');

        return response()->json(['data' => $this->profile->workData($employment, $this->asOf($request))]);
    }

    /**
     * HIS-5 · Línea de tiempo laboral: ingreso, movimientos con su antes y después, sueldo (solo con el permiso
     * de campo; su lectura queda registrada), contratos y adendas, estados y cese. ?types=movement,contract…
     * filtra; un tipo sin permiso no se devuelve (meta.types dice cuáles puede ver el usuario).
     */
    public function timeline(Request $request, string $id): JsonResponse
    {
        $employment = $this->findEmployment($request, $id, 'people.employments.view');
        $visible = $this->timeline->visibleTypes($this->permissions($request));
        $wanted = $request->query('types');
        $types = $visible;
        if (is_string($wanted) && $wanted !== '') {
            $asked = array_values(array_filter(array_map('trim', explode(',', $wanted))));
            if (array_diff($asked, EmploymentTimeline::TYPES) !== []) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['types']);
            }
            $types = array_values(array_intersect($visible, $asked));
        }
        $events = $this->timeline->events($employment, $types, $this->permissions($request));
        $events = $this->sensitive->presentMany('TimelineEvent', $events, $this->permissions($request), 'api', array_map(fn () => $employment->person_id, $events));

        return response()->json(['data' => $events, 'meta' => ['types' => $visible, 'total' => count($events)]]);
    }

    public function assignments(Request $request, string $employmentId): JsonResponse
    {
        $employment = $this->findEmployment($request, $employmentId, 'people.assignments.view');
        $rows = $this->movements->history($employment, $this->asOf($request));

        return response()->json(['data' => $rows, 'meta' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows), 'next_cursor' => null]]);
    }

    public function assignment(Request $request, string $employmentId, string $id): JsonResponse
    {
        $employment = $this->findEmployment($request, $employmentId, 'people.assignments.view');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $row = $this->movements->find($employment, $id);
        abort_if($row === null, 404);

        return response()->json(['data' => $row]);
    }

    private function asOf(Request $request): ?string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null || $asOf === '') {
            return null;
        }
        if (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || strtotime($asOf) === false) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of']);
        }

        return $asOf;
    }
}
